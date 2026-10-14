<?php
// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);
namespace Meridian\Automation;

use Meridian\Auth\{AuditLog, AuthService, Clock, CsrfGuard};
use Meridian\Config;
use Meridian\Database\{Connection, Timestamp};
use Meridian\Http\{ClientIp, JsonBody, JsonReply, Kernel, SessionAuth, ValidationFailed};
use Meridian\Job\{JobRepository, Row};
use Meridian\Monitor\MonitorRepository;
use Meridian\Schedule\CronSchedule;
use Meridian\Security\{AccessControl, Permission, SecretBox, SecretMasker};
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\{Request, Response};

final class Controller
{
    public function __construct(private readonly Connection $db, private readonly SessionAuth $sessions, private readonly CsrfGuard $csrf,
        private readonly UserRepository $users, private readonly AuthService $auth, private readonly AccessControl $access,
        private readonly AuditLog $audit, private readonly Clock $clock, private readonly Config $config,
        private readonly SecretBox $box, private readonly SecretMasker $masker) {}

    public function register(Kernel $kernel): void
    {
        $kernel->get('automation_list','/api/automations',$this->list(...));
        $kernel->post('automation_create','/api/automations',$this->save(...));
        $kernel->put('automation_update','/api/automations/{id}',$this->save(...),['id'=>'[1-9][0-9]{0,17}']);
        $kernel->delete('automation_delete','/api/automations/{id}',$this->delete(...),['id'=>'[1-9][0-9]{0,17}']);
        $kernel->post('automation_test','/api/automations/{id}/test',$this->test(...),['id'=>'[1-9][0-9]{0,17}']);
    }

    private function owner(Request $request): int|Response
    {
        $session=$this->sessions->authenticate($request);
        if ($session === null) return JsonReply::error(401,'Nicht angemeldet.');
        if ($request->getMethod() !== 'GET' && !$this->csrf->check($request,$session->token)) return JsonReply::csrfFailed();
        $this->access->require($this->users->grantsFor($session->userId),Permission::ManageAutomations);
        return $session->userId;
    }

    public function list(Request $request): Response
    {
        $owner=$this->owner($request); if ($owner instanceof Response) return $owner;
        $rules=[];
        $history=[];
        foreach ($this->db->fetchAll('SELECT * FROM (SELECT e.*, ROW_NUMBER() OVER (PARTITION BY e.automation_id ORDER BY e.id DESC) AS position FROM automation_executions e JOIN automations a ON a.id=e.automation_id WHERE a.owner_id=:owner) WHERE position<=10 ORDER BY id DESC',['owner'=>$owner]) as $entry) {
            $history[Row::int($entry,'automation_id')][]=['id'=>Row::int($entry,'id'),'at'=>Row::string($entry,'occurred_at'),'status'=>Row::string($entry,'status'),'detail'=>Row::string($entry,'detail'),'actions'=>json_decode(Row::string($entry,'actions'),true)];
        }
        foreach ($this->db->fetchAll('SELECT * FROM automations WHERE owner_id=:owner ORDER BY id DESC LIMIT 200',['owner'=>$owner]) as $row) {
            $data=JsonBody::object(Row::string($row,'definition'),16384,8) ?? [];
            $data['id']=Row::int($row,'id'); $data['next_at']=Row::stringOrNull($row,'next_at'); $data['last_at']=Row::stringOrNull($row,'last_at');
            $data['enabled']=Row::bool($row,'enabled');
            $data['executions']=$history[Row::int($row,'id')] ?? [];
            $rules[]=$data;
        }
        $grants=$this->users->grantsFor($owner); $scope=$this->access->scope($grants,Permission::ViewJobs);
        $jobs=[]; foreach ((new JobRepository($this->db,$this->box,$this->clock))->list($scope,null,null,false,\Meridian\Job\JobSort::Name)->jobs as $job) $jobs[]=['id'=>$job->id,'name'=>$this->masker->mask($job->name),'type'=>$job->type->value];
        $monitors=[]; foreach ((new MonitorRepository($this->db))->list($scope) as $monitor) $monitors[]=['id'=>$monitor->id,'name'=>$this->masker->mask($monitor->name)];
        $channels=[]; foreach ($this->db->fetchAll('SELECT id,name,type FROM notification_channels WHERE is_enabled=1 ORDER BY name') as $row) $channels[]=['id'=>Row::int($row,'id'),'name'=>$this->masker->mask(Row::string($row,'name')),'type'=>Row::string($row,'type')];
        return JsonReply::json(['automations'=>$rules,'jobs'=>$jobs,'monitors'=>$monitors,'channels'=>$channels]);
    }

    public function save(Request $request): Response
    {
        $owner=$this->owner($request); if ($owner instanceof Response) return $owner;
        $id=$request->attributes->getString('id');
        if ($id !== '' && $this->db->fetchOne('SELECT id FROM automations WHERE id=:id AND owner_id=:owner',['id'=>(int)$id,'owner'=>$owner]) === null) return JsonReply::error(404,'Automation nicht gefunden.');
        $data=JsonBody::object($request->getContent(),16384,8);
        if ($data === null || ($data !== [] && array_is_list($data))) throw ValidationFailed::field('body','JSON-Objekt mit Auslöser, Bedingungen und Aktionen erwartet.');
        $definition=Definition::read($data,$this->db,$this->users->grantsFor($owner));
        if ($definition->enabled) {
            $password=$data['current_password'] ?? null;
            if (!is_string($password) || $password === '' || strlen($password)>AuthService::MAX_PASSWORD) throw ValidationFailed::field('current_password','Zum Aktivieren oder Ändern einer aktiven Automation das eigene aktuelle Passwort bestätigen.');
            $user=$this->users->findById($owner); if ($user === null) return JsonReply::error(401,'Nicht angemeldet.');
            $ip=ClientIp::forThrottle($request,$this->config); if ($ip instanceof Response) return $ip;
            if (!$this->auth->confirmPassword($user,$password,$ip)) return JsonReply::error(403,'Das aktuelle Passwort ist falsch.');
        }
        $now=Timestamp::format($this->clock->now());
        $next=$definition->enabled && $definition->event === 'schedule' ? Timestamp::format(CronSchedule::forJob($definition->cron,$definition->timezone)->nextAfter($this->clock->now())) : null;
        $saved=$this->db->immediate(function () use ($owner,$id,$definition,$now,$next): int {
            if ($id === '') {
                $count=$this->db->fetchOne('SELECT COUNT(*) AS n FROM automations');
                if ($count !== null && Row::int($count,'n')>=200) throw ValidationFailed::field('name','Höchstens 200 Automationen pro Installation.');
                $this->db->execute('INSERT INTO automations(owner_id,name,enabled,definition,next_at,created_at,updated_at) VALUES(:owner,:name,:enabled,:definition,:next,:now,:now)',['owner'=>$owner,'name'=>$definition->name,'enabled'=>(int)$definition->enabled,'definition'=>$definition->json(),'next'=>$next,'now'=>$now]);
                $saved=$this->db->lastInsertId();
            } else {
                $saved=(int)$id;
                $this->db->execute('UPDATE automations SET name=:name,enabled=:enabled,definition=:definition,next_at=:next,updated_at=:now WHERE id=:id AND owner_id=:owner',['id'=>$saved,'owner'=>$owner,'name'=>$definition->name,'enabled'=>(int)$definition->enabled,'definition'=>$definition->json(),'next'=>$next,'now'=>$now]);
            }
            $this->audit->record($owner,$id === '' ? 'automation.created' : 'automation.updated','automation:' . $saved);
            return $saved;
        });
        return JsonReply::json(['id'=>$saved],$id === '' ? 201 : 200);
    }

    public function delete(Request $request): Response
    {
        $owner=$this->owner($request); if ($owner instanceof Response) return $owner;
        $deleted=$this->db->immediate(function () use ($owner,$request): int {
            $id=(int)$request->attributes->getString('id');
            $deleted=$this->db->execute('DELETE FROM automations WHERE id=:id AND owner_id=:owner',['id'=>$id,'owner'=>$owner]);
            if ($deleted) $this->audit->record($owner,'automation.deleted','automation:' . $id);
            return $deleted;
        });
        return $deleted ? new Response(null,204,['Cache-Control'=>'no-store']) : JsonReply::error(404,'Automation nicht gefunden.');
    }

    public function test(Request $request): Response
    {
        $owner=$this->owner($request); if ($owner instanceof Response) return $owner;
        $row=$this->db->fetchOne('SELECT definition FROM automations WHERE id=:id AND owner_id=:owner',['id'=>(int)$request->attributes->getString('id'),'owner'=>$owner]);
        if ($row === null) return JsonReply::error(404,'Automation nicht gefunden.');
        $rule=Definition::read(JsonBody::object(Row::string($row,'definition'),16384,8) ?? [],$this->db,$this->users->grantsFor($owner));
        $checks=(new Engine($this->db,$this->clock,$this->users,$this->box,$this->audit,$this->masker))->conditions($rule);
        return JsonReply::json(['conditions'=>$checks,'matched'=>$checks === [] || ($rule->logic === 'all' ? !in_array(false,$checks,true) : in_array(true,$checks,true)), 'executed'=>false]);
    }
}
