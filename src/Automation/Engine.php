<?php
// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);
namespace Meridian\Automation;

use Meridian\Auth\{AuditLog, Clock};
use Meridian\Database\{Connection, Timestamp};
use Meridian\Http\JsonBody;
use Meridian\Job\{JobRepository, Row, RunService};
use Meridian\Schedule\{CronSchedule, RunTrigger};
use Meridian\Security\{AccessControl, Permission, SecretBox, SecretMasker};
use Meridian\User\UserRepository;

/** Persistent events and action writes commit together. No shell or network calls are made by this engine. */
final class Engine
{
    private float $lastPrune = 0.0;
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly UserRepository $users,
        private readonly SecretBox $box, private readonly AuditLog $audit, private readonly SecretMasker $masker) {}

    public function tick(): void
    {
        $now = Timestamp::format($this->clock->now());
        foreach ($this->db->fetchAll('SELECT id FROM automation_events WHERE processed_at IS NULL ORDER BY id LIMIT 20') as $entry) {
            $this->db->immediate(function () use ($entry, $now): void {
                $event = $this->db->fetchOne('SELECT * FROM automation_events WHERE id=:id AND processed_at IS NULL', ['id'=>Row::int($entry,'id')]);
                if ($event === null) return;
                $rules = $this->db->fetchAll("SELECT * FROM automations WHERE enabled=1 AND json_extract(definition,'$.event')=:event AND json_extract(definition,'$.source_id')=:source AND created_at<=:at ORDER BY id", ['event'=>Row::string($event,'event'),'source'=>Row::int($event,'source_id'),'at'=>Row::string($event,'occurred_at')]);
                $chainRaw = json_decode(Row::string($event,'chain'), true);
                $chain = [];
                if (is_array($chainRaw)) { foreach ($chainRaw as $id) { if (is_int($id) && $id > 0) $chain[] = $id; } }
                foreach ($rules as $rule) $this->execute($rule, 'event:' . Row::int($event,'id'), $chain);
                $this->db->execute('UPDATE automation_events SET processed_at=:now WHERE id=:id', ['now'=>$now,'id'=>Row::int($entry,'id')]);
            });
        }
        foreach ($this->db->fetchAll('SELECT id FROM automations WHERE enabled=1 AND next_at IS NOT NULL AND next_at<=:now ORDER BY next_at,id LIMIT 20', ['now'=>$now]) as $entry) {
            $this->db->immediate(function () use ($entry,$now): void {
                $rule = $this->db->fetchOne('SELECT * FROM automations WHERE id=:id AND enabled=1 AND next_at<=:now', ['id'=>Row::int($entry,'id'),'now'=>$now]);
                if ($rule === null) return;
                $this->execute($rule, 'schedule:' . Row::string($rule,'next_at'), []);
                try {
                    $data = JsonBody::object(Row::string($rule,'definition'),16384,8) ?? [];
                    $definition = Definition::read($data, $this->db, $this->users->grantsFor(Row::int($rule,'owner_id')));
                    $next = Timestamp::format(CronSchedule::forJob($definition->cron,$definition->timezone)->nextAfter($this->clock->now()));
                    $this->db->execute('UPDATE automations SET next_at=:next WHERE id=:id', ['next'=>$next,'id'=>Row::int($rule,'id')]);
                } catch (\Throwable) { $this->db->execute('UPDATE automations SET enabled=0,next_at=NULL WHERE id=:id', ['id'=>Row::int($rule,'id')]); }
            });
        }
        if (microtime(true) - $this->lastPrune < 600) return;
        $this->lastPrune = microtime(true);
        // Bounded retention is independent of the job-run retention policy.
        $cutoff = Timestamp::format($this->clock->now()->modify('-90 days'));
        $this->db->execute('DELETE FROM automation_events WHERE id IN (SELECT id FROM automation_events WHERE processed_at IS NOT NULL AND occurred_at<:cutoff LIMIT 1000)', ['cutoff'=>$cutoff]);
        $this->db->execute('DELETE FROM automation_executions WHERE id IN (SELECT id FROM automation_executions WHERE occurred_at<:cutoff LIMIT 1000)', ['cutoff'=>$cutoff]);
    }

    /** @return list<bool> */
    public function conditions(Definition $rule): array
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone($rule->timezone));
        $results = [];
        foreach ($rule->conditions as $condition) {
            $kind = $condition['kind']; $id = $condition['id'];
            if ($kind === 'time') {
                $time = $now->format('H:i'); $start=$condition['start']; $end=$condition['end'];
                $results[] = $start === $end || ($start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end);
            } elseif ($kind === 'weekday') {
                $results[] = in_array((int)$now->format('N'),$condition['days'],true);
            } elseif ($kind === 'monitor_state') {
                $row=$this->db->fetchOne('SELECT state,is_enabled FROM monitors WHERE id=:id',['id'=>$id]);
                $results[] = $row !== null && ($condition['value'] === 'paused' ? $row['is_enabled'] === 0 : $row['is_enabled'] === 1 && $row['state'] === $condition['value']);
            } elseif ($kind === 'job_failures') {
                $runs=$this->db->fetchAll("SELECT status FROM runs WHERE job_id=:id AND trigger<>'test' AND finished_at IS NOT NULL ORDER BY finished_at DESC,id DESC LIMIT :limit",['id'=>$id,'limit'=>$condition['count']]);
                $results[] = count($runs) === $condition['count'] && count(array_filter($runs,static fn(array $run): bool => in_array($run['status'],['failed','timeout','aborted'],true))) === count($runs);
            } else {
                $status=$condition['value'];
                $row = in_array($status,['queued','running'],true)
                    ? $this->db->fetchOne('SELECT status FROM runs WHERE job_id=:id AND status=:status LIMIT 1',['id'=>$id,'status'=>$status])
                    : $this->db->fetchOne("SELECT status FROM runs WHERE job_id=:id AND trigger<>'test' AND finished_at IS NOT NULL ORDER BY finished_at DESC,id DESC LIMIT 1",['id'=>$id]);
                $results[] = $row !== null && $row['status'] === $status;
            }
        }
        return $results;
    }

    /**
     * @param array<string,mixed> $record
     * @param list<int> $chain
     */
    private function execute(array $record, string $key, array $chain): void
    {
        $id=Row::int($record,'id'); $owner=Row::int($record,'owner_id'); $now=Timestamp::format($this->clock->now());
        if ($this->db->fetchOne('SELECT id FROM automation_executions WHERE automation_id=:id AND event_key=:key',['id'=>$id,'key'=>$key]) !== null) return;
        $status='skipped'; $detail='Bedingungen nicht erfüllt.'; $results=[];
        $this->db->execute('SAVEPOINT automation_action');
        try {
            $grants=$this->users->grantsFor($owner); (new AccessControl())->require($grants,Permission::ManageAutomations);
            $rule=Definition::read(JsonBody::object(Row::string($record,'definition'),16384,8) ?? [],$this->db,$grants);
            $checks=$this->conditions($rule);
            $matched=$checks === [] || ($rule->logic === 'all' ? !in_array(false,$checks,true) : in_array(true,$checks,true));
            $last=Row::stringOrNull($record,'last_at');
            if (in_array($id,$chain,true) || count($chain)>=8) $detail='Automationsschleife verhindert.';
            elseif ($last !== null && $this->clock->now()->getTimestamp()-Timestamp::parse($last)->getTimestamp()<$rule->cooldown) $detail='Mindestabstand noch nicht erreicht.';
            elseif ($matched) {
                $runner=new RunService($this->db,new JobRepository($this->db,$this->box,$this->clock),new AccessControl(),$this->audit,$this->clock);
                foreach ($rule->actions as $action) {
                    if ($action['kind'] === 'job') {
                        $run=$runner->enqueue($owner,$grants,$action['id'],RunTrigger::Manual,$id,[...$chain,$id]);
                        if ($run === null) throw new \RuntimeException('Target unavailable.');
                        $results[]=['kind'=>'job','id'=>$action['id'],'run_id'=>$run];
                    } else {
                        foreach ($action['ids'] as $channel) {
                            $this->db->execute("INSERT INTO notification_outbox(kind,channel_id,job_name,occurred_at,detail,next_attempt_at,created_at,browser_user_id,automation_id) VALUES('automation',:channel,:name,:now,:detail,:now,:now,:owner,:rule)",['channel'=>$channel,'name'=>$this->masker->mask($rule->name),'now'=>$now,'detail'=>$this->masker->mask($action['text']),'owner'=>$owner,'rule'=>$id]);
                            $results[]=['kind'=>'notify','id'=>$channel,'delivery_id'=>$this->db->lastInsertId()];
                        }
                    }
                }
                $status='executed'; $detail='Aktionen eingereiht.';
                $this->db->execute('UPDATE automations SET last_at=:now WHERE id=:id',['now'=>$now,'id'=>$id]);
                $this->audit->record($owner,'automation.executed','automation:' . $id);
            }
        } catch (\Throwable) { $this->db->execute('ROLLBACK TO automation_action'); $results=[]; $status='failed'; $detail='Aktion nicht möglich: Rechte, aktive Ziele und Ausführungslimits prüfen.'; }
        $this->db->execute('RELEASE automation_action');
        $this->db->execute('INSERT INTO automation_executions(automation_id,event_key,occurred_at,status,detail,actions) VALUES(:id,:key,:now,:status,:detail,:actions)', ['id'=>$id,'key'=>$key,'now'=>$now,'status'=>$status,'detail'=>$detail,'actions'=>json_encode($results,JSON_THROW_ON_ERROR)]);
    }
}
