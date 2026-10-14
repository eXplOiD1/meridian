<?php
// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);
namespace Meridian\Automation;

use Meridian\Database\Connection;
use Meridian\Http\ValidationFailed;
use Meridian\Job\Row;
use Meridian\Schedule\CronSchedule;
use Meridian\Security\{AccessControl, Permission, RoleGrant};

/** Validated, executable rule; contains identifiers and plain notification text, never scripts or credentials. */
final readonly class Definition
{
    /** @param list<array{kind:string,id:int,value:string,start:string,end:string,days:list<int>,count:int}> $conditions
     * @param list<array{kind:string,id:int,ids:list<int>,text:string}> $actions */
    public function __construct(public string $name, public bool $enabled, public string $event, public int $sourceId,
        public string $cron, public string $timezone, public string $logic, public array $conditions, public array $actions, public int $cooldown) {}

    /**
     * @param array<mixed> $data
     * @param list<RoleGrant> $grants
     */
    public static function read(array $data, Connection $db, array $grants): self
    {
        $bad = static function (string $field, string $message): never { throw ValidationFailed::field($field, $message); };
        if (array_diff(array_keys($data), ['name','enabled','event','source_id','cron','timezone','logic','conditions','actions','cooldown_seconds','current_password']) !== []) { $bad('body', 'Unbekanntes Feld in der Automation.'); }
        $name = $data['name'] ?? null; $enabled = $data['enabled'] ?? true;
        $event = $data['event'] ?? null; $source = $data['source_id'] ?? 0;
        $cron = $data['cron'] ?? '0 * * * *'; $timezone = $data['timezone'] ?? 'Europe/Berlin';
        $logic = $data['logic'] ?? 'all'; $cooldown = $data['cooldown_seconds'] ?? 60;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name)) { $bad('name', 'Name: 1 bis 100 Zeichen ohne Steuerzeichen.'); }
        if (!is_bool($enabled) || !is_string($event) || !in_array($event, ['schedule','job.ok','job.failed','job.timeout','job.aborted','job.recovery','monitor.up','monitor.down'], true)) { $bad('event', 'Gültigen Auslöser auswählen.'); }
        if (!is_string($timezone) || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) { $bad('timezone', 'Gültige Zeitzone angeben.'); }
        if (!is_string($cron) || strlen($cron) > 100 || ($event === 'schedule' && count(preg_split('/\s+/', trim($cron)) ?: []) !== 5)) { $bad('cron', 'Zeitplan: fünf Cron-Felder (Minute Stunde Tag Monat Wochentag).'); }
        if ($event === 'schedule') {
            try { CronSchedule::forJob($cron, $timezone)->nextAfter(new \DateTimeImmutable()); } catch (\Throwable) { $bad('cron', 'Ungültiger Zeitplan.'); }
        }
        if (!is_int($source) || $source < 0 || ($event !== 'schedule' && $source < 1)) { $bad('source_id', 'Job oder Überwachung auswählen.'); }
        if ($event !== 'schedule') { self::entity($db, $grants, str_starts_with($event, 'job.') ? 'job' : 'monitor', $source, false); }
        if (!is_string($logic) || !in_array($logic, ['all','any'], true) || !is_int($cooldown) || $cooldown < 0 || $cooldown > 86400) { $bad('logic', 'Bedingungen: alle oder mindestens eine; Mindestabstand 0 bis 86400 Sekunden.'); }
        $rawConditions = $data['conditions'] ?? []; $rawActions = $data['actions'] ?? [];
        if (!is_array($rawConditions) || !array_is_list($rawConditions) || count($rawConditions) > 10 || !is_array($rawActions) || !array_is_list($rawActions) || count($rawActions) < 1 || count($rawActions) > 5) { $bad('actions', 'Höchstens zehn Bedingungen und eine bis fünf Aktionen angeben.'); }
        $conditions = [];
        /** @var mixed $entry */
        foreach ($rawConditions as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), ['kind','id','value','start','end','days','count']) !== []) { $bad('conditions', 'Ungültige Bedingung.'); }
            $kind = $entry['kind'] ?? null; $id = $entry['id'] ?? 0; $value = $entry['value'] ?? '';
            $start = $entry['start'] ?? '00:00'; $end = $entry['end'] ?? '23:59'; $days = $entry['days'] ?? []; $count = $entry['count'] ?? 1;
            if (!is_string($kind) || !in_array($kind, ['job_status','job_failures','monitor_state','time','weekday'], true) || !is_int($id) || !is_string($value) || !is_string($start) || !is_string($end) || !is_int($count) || $count < 1 || $count > 100 || !is_array($days) || !array_is_list($days) || count($days) > 7) { $bad('conditions', 'Ungültige Bedingung.'); }
            $dayList = [];
            /** @var mixed $day */
            foreach ($days as $day) { if (!is_int($day) || $day < 1 || $day > 7) { $bad('conditions', 'Wochentage: Montag 1 bis Sonntag 7.'); } $dayList[] = $day; }
            if (in_array($kind, ['job_status','job_failures','monitor_state'], true)) { self::entity($db, $grants, $kind === 'monitor_state' ? 'monitor' : 'job', $id, false); }
            if (($kind === 'job_status' && !in_array($value, ['ok','failed','timeout','aborted','queued','running'], true)) || ($kind === 'monitor_state' && !in_array($value, ['up','down','waiting','paused'], true))) { $bad('conditions', 'Gültigen Zustand auswählen.'); }
            if ($kind === 'time' && (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $start) !== 1 || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $end) !== 1)) { $bad('conditions', 'Uhrzeiten im Format HH:MM angeben.'); }
            if ($kind === 'weekday' && $dayList === []) { $bad('conditions', 'Mindestens einen Wochentag auswählen.'); }
            $conditions[] = ['kind'=>$kind,'id'=>$id,'value'=>$value,'start'=>$start,'end'=>$end,'days'=>$dayList,'count'=>$count];
        }
        $actions = [];
        /** @var mixed $entry */
        foreach ($rawActions as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), ['kind','id','ids','text']) !== []) { $bad('actions', 'Ungültige Aktion.'); }
            $kind = $entry['kind'] ?? null; $id = $entry['id'] ?? 0; $ids = $entry['ids'] ?? []; $text = $entry['text'] ?? '';
            if (!is_string($kind) || !in_array($kind, ['job','notify'], true) || !is_int($id) || !is_array($ids) || !array_is_list($ids) || count($ids) > 10 || !is_string($text) || mb_strlen($text) > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)) { $bad('actions', 'Job starten oder Benachrichtigung mit höchstens 1000 Zeichen auswählen.'); }
            if ($kind === 'job') { self::entity($db, $grants, 'job', $id, true); }
            $channelIds = [];
            /** @var mixed $channel */
            foreach ($ids as $channel) { if (!is_int($channel) || $channel < 1 || $db->fetchOne('SELECT id FROM notification_channels WHERE id=:id AND is_enabled=1', ['id'=>$channel]) === null) { $bad('actions', 'Aktiven Benachrichtigungskanal auswählen.'); } $channelIds[] = $channel; }
            if ($kind === 'notify' && ($channelIds === [] || trim($text) === '')) { $bad('actions', 'Nachricht und mindestens einen aktiven Kanal angeben.'); }
            $actions[] = ['kind'=>$kind,'id'=>$id,'ids'=>array_values(array_unique($channelIds)),'text'=>$text];
        }
        return new self(trim($name), $enabled, $event, $source, $cron, $timezone, $logic, $conditions, $actions, $cooldown);
    }

    /** @param list<RoleGrant> $grants */
    public static function entity(Connection $db, array $grants, string $kind, int $id, bool $run): void
    {
        $row = $kind === 'job' ? $db->fetchOne('SELECT j.id,c.name AS category FROM jobs j LEFT JOIN categories c ON c.id=j.category_id WHERE j.id=:id', ['id'=>$id])
            : $db->fetchOne('SELECT m.id,c.name AS category FROM monitors m LEFT JOIN categories c ON c.id=m.category_id WHERE m.id=:id', ['id'=>$id]);
        if ($row === null) { throw ValidationFailed::field('source_id', 'Eintrag nicht vorhanden oder nicht mehr verfügbar.'); }
        $access = new AccessControl();
        $access->require($grants, Permission::ViewJobs, Row::stringOrNull($row, 'category'));
        if ($run) { $access->require($grants, Permission::RunJobs, Row::stringOrNull($row, 'category')); }
    }

    /** @return array<string,mixed> */
    public function data(): array { return ['name'=>$this->name,'enabled'=>$this->enabled,'event'=>$this->event,'source_id'=>$this->sourceId,'cron'=>$this->cron,'timezone'=>$this->timezone,'logic'=>$this->logic,'conditions'=>$this->conditions,'actions'=>$this->actions,'cooldown_seconds'=>$this->cooldown]; }
    public function json(): string { return json_encode($this->data(), JSON_THROW_ON_ERROR); }
}
