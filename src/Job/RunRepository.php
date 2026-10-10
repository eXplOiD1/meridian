<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Database\Connection;
use Meridian\Runner\JobType;
use Meridian\Runner\LiveStream;
use Meridian\Schedule\RunStatus;
use Meridian\Schedule\RunTrigger;
use Meridian\Security\CategoryScope;

/**
 * Läufe lesen. Läufe erben die Kategorie ihres Jobs und kappen über dasselbe Prädikat wie
 * {@see JobRepository} ({@see ScopeSql::PREDICATE}). Die Spalten sind eine Allowlist: `worker`,
 * `heartbeat_at` und `exec_ref` werden nie geladen (H2), `exit_code` ist für HTTP-Läufe ohne Bedeutung.
 */
final class RunRepository
{
    public const MAX_LIMIT = 100;

    /**
     * Die eine Abfrage für Verlauf und Einzelabruf. Das Prädikat der Sichtbarkeit ist derselbe Text wie
     * {@see ScopeSql::PREDICATE} (ein Test prüft das). `output` liefert nur der Einzelabruf. Ausgewählt wird nie
     * `worker`, `heartbeat_at` oder `exec_ref` (H2). Unter 1000 Zeichen halten: längere Konstanten sieht Psalm nicht
     * mehr als `literal-string`.
     */
    private const QUERY = 'SELECT r.id, r.job_id, r.trigger, r.status, r.attempt, r.scheduled_for, r.started_at, r.finished_at,
r.duration_ms, r.http_status, r.note, r.started_by, u.display_name AS started_by_name, c.name AS category_name,
j.type AS job_type, j.name AS job_name, CASE WHEN :with_output = 1 THEN r.output END AS output, r.exit_code,
r.cancel_requested_at, r.cancel_requested_by, cu.display_name AS cancelled_by_name, r.output_bytes,
EXISTS (SELECT 1 FROM run_log_chunks k WHERE k.run_id = r.id) AS live
FROM runs r JOIN jobs j ON j.id = r.job_id LEFT JOIN categories c ON c.id = j.category_id
LEFT JOIN users u ON u.id = r.started_by LEFT JOIN users cu ON cu.id = r.cancel_requested_by
WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))
AND (:run IS NULL OR r.id = :run) AND (:job IS NULL OR r.job_id = :job) AND (:before IS NULL OR r.id < :before)
AND (:status IS NULL OR r.status = :status) ORDER BY r.id DESC LIMIT :limit';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Verlauf eines Jobs, neueste zuerst, ohne Ausgabe. Liefert bis zu `$limit + 1` Läufe, damit der Aufrufer
     * erkennt, ob es eine weitere Seite gibt.
     *
     * @return list<RunRecord>
     */
    public function history(int $jobId, CategoryScope $scope, int $limit, ?int $beforeId, ?RunStatus $status): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $rows = $this->db->fetchAll(self::QUERY, $scope->sqlParameters() + [
            'with_output' => 0,
            'run' => null,
            'job' => $jobId,
            'before' => $beforeId,
            'status' => $status?->value,
            'limit' => $limit + 1,
        ]);

        return $this->records($rows);
    }

    /** Ein Lauf (mit Ausgabe, falls verlangt), falls sein Job im Bereich liegt; sonst null (→ 404). */
    public function findVisible(int $runId, CategoryScope $scope, bool $withOutput = true): ?RunRecord
    {
        $rows = $this->db->fetchAll(self::QUERY, $scope->sqlParameters() + [
            'with_output' => $withOutput ? 1 : 0,
            'run' => $runId,
            'job' => null,
            'before' => null,
            'status' => null,
            'limit' => 1,
        ]);

        return $this->records($rows)[0] ?? null;
    }

    /**
     * Stücke des Live-Logs nach `$afterSeq`, in Reihenfolge. Nur nach `findVisible()` und `require()` für denselben
     * Lauf aufrufen: diese Abfrage prüft keinen Bereich.
     *
     * @return list<LiveChunk>
     */
    public function chunksAfter(int $runId, int $afterSeq, int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT seq, stream, data FROM run_log_chunks WHERE run_id = :run AND seq > :after ORDER BY seq LIMIT :limit',
            ['run' => $runId, 'after' => max(0, $afterSeq), 'limit' => max(1, min($limit, LiveChunk::MAX_LIMIT))],
        );
        $chunks = [];
        foreach ($rows as $row) {
            $stream = LiveStream::tryFrom(Row::string($row, 'stream'));
            if ($stream === null) {
                throw new \UnexpectedValueException('Live-Log von Lauf ' . $runId . ' hat einen unbekannten Strom.');
            }
            $chunks[] = new LiveChunk(Row::int($row, 'seq'), $stream, Row::string($row, 'data'));
        }

        return $chunks;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<RunRecord>
     */
    private function records(array $rows): array
    {
        $records = [];
        foreach ($rows as $row) {
            $trigger = RunTrigger::tryFrom(Row::string($row, 'trigger'));
            $status = RunStatus::tryFrom(Row::string($row, 'status'));
            $type = JobType::tryFrom(Row::string($row, 'job_type'));
            if ($trigger === null || $status === null || $type === null) {
                throw new \UnexpectedValueException('Lauf ' . Row::int($row, 'id') . ' hat einen unbekannten Auslöser, Status oder Job-Typ.');
            }
            $records[] = new RunRecord(
                Row::int($row, 'id'),
                Row::int($row, 'job_id'),
                $trigger,
                $status,
                Row::int($row, 'attempt'),
                Row::stringOrNull($row, 'scheduled_for'),
                Row::stringOrNull($row, 'started_at'),
                Row::stringOrNull($row, 'finished_at'),
                Row::intOrNull($row, 'duration_ms'),
                Row::intOrNull($row, 'http_status'),
                Row::stringOrNull($row, 'note'),
                Row::stringOrNull($row, 'output'),
                Row::intOrNull($row, 'started_by'),
                Row::stringOrNull($row, 'started_by_name'),
                Row::stringOrNull($row, 'category_name'),
                $type,
                Row::intOrNull($row, 'exit_code'),
                Row::stringOrNull($row, 'cancel_requested_at'),
                Row::intOrNull($row, 'cancel_requested_by'),
                Row::stringOrNull($row, 'cancelled_by_name'),
                Row::intOrNull($row, 'output_bytes'),
                Row::int($row, 'live') === 1,
                Row::string($row, 'job_name'),
            );
        }

        return $records;
    }
}
