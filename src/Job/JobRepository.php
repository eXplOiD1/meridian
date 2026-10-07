<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Runner\JobType;
use Meridian\Schedule\OverlapPolicy;
use Meridian\Schedule\RunStatus;
use Meridian\Schedule\RunTrigger;
use Meridian\Security\CategoryScope;
use Meridian\Security\SecretBox;

/**
 * Jobs lesen und schreiben (docs/decisions/0003, E1/E3/E6).
 *
 * Lesen: jede Abfrage kappt über {@see ScopeSql::PREDICATE} auf den `CategoryScope`; `payload_enc` wird nie
 * geladen. Schreiben: die Anfrage (URL, Header, Body) wird hier nur verschlüsselt, nie entschlüsselt;
 * `next_run_at` setzt ein Anlegen/Ändern immer auf NULL zurück, den neuen Termin berechnet danach
 * `Planner::reschedule()` (H6). Transaktionen öffnet der Aufrufer ({@see JobService}).
 */
final class JobRepository
{
    public const LIST_LIMIT = 500;

    /**
     * Die eine Abfrage für Liste und Einzelabruf: das Prädikat der Sichtbarkeit steht genau hier (derselbe Text wie
     * {@see ScopeSql::PREDICATE}, ein Test prüft das), `:id` und die Filter sind nur Anfragen innerhalb davon.
     * `payload_enc` wird nie ausgewählt.
     */
    private const QUERY = 'SELECT j.id, j.name, j.type, j.category_id, c.name AS category_name, j.owner_id, u.display_name AS owner_name,
            j.cron, j.timezone, j.config_json, j.overlap_policy, j.retry_count, j.retry_delay_seconds, j.catch_up, j.is_enabled,
            j.next_run_at, j.created_at, j.updated_at
       FROM jobs j
       LEFT JOIN categories c ON c.id = j.category_id
       LEFT JOIN users u ON u.id = j.owner_id
      WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))
        AND (:id IS NULL OR j.id = :id)
        AND (:type IS NULL OR j.type = :type)
        AND (:category IS NULL OR j.category_id = :category)
        AND (:enabled_only = 0 OR j.is_enabled = 1)
      ORDER BY CASE WHEN :by_name = 1 THEN j.name END COLLATE NOCASE,
               CASE WHEN :by_name = 0 THEN j.next_run_at IS NULL END,
               CASE WHEN :by_name = 0 THEN j.next_run_at END,
               j.id
      LIMIT :limit';

    /**
     * Letzter abgeschlossener Lauf (ohne Testläufe, ohne übersprungene) und „läuft gerade“ für eine Menge Jobs.
     */
    private const RUN_STATE = 'SELECT j.id AS job_id, lr.id AS run_id, lr.trigger, lr.status, lr.finished_at, lr.duration_ms, lr.http_status,
            EXISTS (SELECT 1 FROM runs r2 WHERE r2.job_id = j.id AND r2.status = \'running\') AS running
       FROM jobs j
       LEFT JOIN runs lr ON lr.id = (SELECT r.id FROM runs r WHERE r.job_id = j.id AND r.trigger <> \'test\'
                                      AND r.status IN (\'ok\', \'failed\', \'timeout\', \'aborted\') ORDER BY r.id DESC LIMIT 1)
      WHERE j.id IN (SELECT value FROM json_each(:ids))';

    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $box,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Die Jobs im Bereich, höchstens {@see self::LIST_LIMIT}. Typ, Kategorie und „nur aktive“ sind Anfragen
     * innerhalb des Bereichs, nie eine Berechtigung.
     */
    public function list(CategoryScope $scope, ?JobType $type, ?int $categoryId, bool $enabledOnly, JobSort $sort): JobListing
    {
        $rows = $this->db->fetchAll(self::QUERY, $scope->sqlParameters() + [
            'id' => null,
            'type' => $type?->value,
            'category' => $categoryId,
            'enabled_only' => $enabledOnly ? 1 : 0,
            'by_name' => $sort === JobSort::Name ? 1 : 0,
            'limit' => self::LIST_LIMIT + 1,
        ]);

        $truncated = count($rows) > self::LIST_LIMIT;
        $rows = array_slice($rows, 0, self::LIST_LIMIT);

        return new JobListing($this->records($rows), $truncated);
    }

    /**
     * Ein Job, falls er im Bereich liegt (dieselbe Sichtbarkeit wie die Liste); sonst null (→ 404). Danach muss der
     * Aufrufer das konkrete Recht mit der gespeicherten Kategorie prüfen.
     */
    public function findVisible(int $id, CategoryScope $scope): ?JobRecord
    {
        $rows = $this->db->fetchAll(self::QUERY, $scope->sqlParameters() + [
            'id' => $id,
            'type' => null,
            'category' => null,
            'enabled_only' => 0,
            'by_name' => 1,
            'limit' => 1,
        ]);

        return $this->records($rows)[0] ?? null;
    }

    /**
     * Legt einen Job an (ohne Termin: `next_run_at` NULL, Planner::reschedule() folgt nach dem Commit).
     */
    public function insert(JobDraft $draft, int $ownerId): int
    {
        if ($draft->payload === null) {
            throw new \LogicException('Ein neuer Job braucht eine Anfrage.');
        }
        $now = Timestamp::format($this->clock->now());
        $this->db->execute(
            'INSERT INTO jobs (name, type, category_id, owner_id, cron, timezone, config_json, payload_enc, overlap_policy,
                               retry_count, retry_delay_seconds, catch_up, is_enabled, next_run_at, created_at, updated_at)
             VALUES (:name, :type, :category, :owner, :cron, :timezone, :config, :payload, :overlap,
                     :retry_count, :retry_delay, :catch_up, :enabled, NULL, :created, :updated)',
            [
                'name' => $draft->name,
                'type' => $draft->type->value,
                'category' => $draft->categoryId,
                'owner' => $ownerId,
                'cron' => $draft->cron,
                'timezone' => $draft->timezone,
                'config' => $draft->http->toJson(),
                'payload' => $this->box->encrypt($draft->payload->toJson()),
                'overlap' => $draft->overlapPolicy->value,
                'retry_count' => $draft->retryCount,
                'retry_delay' => $draft->retryDelaySeconds,
                'catch_up' => $draft->catchUp ? 1 : 0,
                'enabled' => $draft->isEnabled ? 1 : 0,
                'created' => $now,
                'updated' => $now,
            ],
        );

        return $this->db->lastInsertId();
    }

    /**
     * Ändert einen Job. Ohne `payload` im Entwurf bleibt `payload_enc` unberührt. `resetSchedule` setzt
     * `next_run_at` auf NULL (Zeitplan oder Aktivierung geändert); den neuen Termin berechnet danach
     * `Planner::reschedule()`.
     */
    public function update(int $id, JobDraft $draft, bool $resetSchedule): void
    {
        $this->db->execute(
            'UPDATE jobs SET name = :name, category_id = :category, cron = :cron, timezone = :timezone, config_json = :config,
                             payload_enc = COALESCE(:payload, payload_enc), overlap_policy = :overlap, retry_count = :retry_count,
                             retry_delay_seconds = :retry_delay, catch_up = :catch_up, is_enabled = :enabled,
                             next_run_at = CASE WHEN :reset = 1 THEN NULL ELSE next_run_at END, updated_at = :updated
              WHERE id = :id',
            [
                'name' => $draft->name,
                'category' => $draft->categoryId,
                'cron' => $draft->cron,
                'timezone' => $draft->timezone,
                'config' => $draft->http->toJson(),
                'payload' => $draft->payload === null ? null : $this->box->encrypt($draft->payload->toJson()),
                'overlap' => $draft->overlapPolicy->value,
                'retry_count' => $draft->retryCount,
                'retry_delay' => $draft->retryDelaySeconds,
                'catch_up' => $draft->catchUp ? 1 : 0,
                'enabled' => $draft->isEnabled ? 1 : 0,
                'reset' => $resetSchedule ? 1 : 0,
                'updated' => Timestamp::format($this->clock->now()),
                'id' => $id,
            ],
        );
    }

    /** Löscht den Job; sein Verlauf geht per ON DELETE CASCADE mit (das Audit-Log bleibt). */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM jobs WHERE id = :id', ['id' => $id]);
    }

    /**
     * Aktiviert (Termin wird danach neu berechnet) oder deaktiviert (in einer Anweisung: kein Termin mehr).
     */
    public function setEnabled(int $id, bool $enabled): void
    {
        $this->db->execute(
            'UPDATE jobs SET is_enabled = :enabled, next_run_at = NULL, updated_at = :updated WHERE id = :id',
            ['enabled' => $enabled ? 1 : 0, 'updated' => Timestamp::format($this->clock->now()), 'id' => $id],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<JobRecord>
     */
    private function records(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = Row::int($row, 'id');
        }
        $states = $this->runStates($ids);

        $records = [];
        foreach ($rows as $row) {
            $type = JobType::tryFrom(Row::string($row, 'type'));
            $overlap = OverlapPolicy::tryFrom(Row::string($row, 'overlap_policy'));
            $id = Row::int($row, 'id');
            if ($type === null || $overlap === null) {
                throw new \UnexpectedValueException('Job ' . $id . ' hat einen unbekannten Typ oder eine unbekannte Überlappungsregel.');
            }
            $records[] = new JobRecord(
                $id,
                Row::string($row, 'name'),
                $type,
                Row::intOrNull($row, 'category_id'),
                Row::stringOrNull($row, 'category_name'),
                Row::intOrNull($row, 'owner_id'),
                Row::stringOrNull($row, 'owner_name'),
                Row::string($row, 'cron'),
                Row::string($row, 'timezone'),
                Row::stringOrNull($row, 'next_run_at'),
                Row::bool($row, 'is_enabled'),
                $overlap,
                Row::int($row, 'retry_count'),
                Row::int($row, 'retry_delay_seconds'),
                Row::bool($row, 'catch_up'),
                $type === JobType::Http ? self::config($id, Row::string($row, 'config_json')) : null,
                $states[$id]['last_run'] ?? null,
                $states[$id]['running'] ?? false,
                Row::string($row, 'created_at'),
                Row::string($row, 'updated_at'),
            );
        }

        return $records;
    }

    /** Eine unlesbare `config_json` macht den Job nicht unsichtbar, sondern nur seine Anfrage-Details leer. */
    private static function config(int $jobId, string $json): ?HttpJobConfig
    {
        try {
            return HttpJobConfig::fromJson($json);
        } catch (InvalidJobConfig) {
            error_log('Meridian: Die Konfiguration von Job ' . $jobId . ' ist unlesbar. Anfrage im Job neu eingeben und speichern.');

            return null;
        }
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{last_run: LastRun|null, running: bool}>
     */
    private function runStates(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->fetchAll(self::RUN_STATE, ['ids' => json_encode($ids, JSON_THROW_ON_ERROR)]);

        $states = [];
        foreach ($rows as $row) {
            $runId = Row::intOrNull($row, 'run_id');
            $status = $runId === null ? null : RunStatus::tryFrom(Row::string($row, 'status'));
            $trigger = $runId === null ? null : RunTrigger::tryFrom(Row::string($row, 'trigger'));
            $last = $runId === null || $status === null || $trigger === null
                ? null
                : new LastRun($runId, $status, $trigger, Row::stringOrNull($row, 'finished_at'), Row::intOrNull($row, 'duration_ms'), Row::intOrNull($row, 'http_status'));
            $states[Row::int($row, 'job_id')] = ['last_run' => $last, 'running' => Row::bool($row, 'running')];
        }

        return $states;
    }
}
