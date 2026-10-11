<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Database\Connection;
use Meridian\Security\CategoryScope;

/**
 * Zahlen für die Übersicht („Heute im Takt“): Läufe je Job in Zeitabschnitten und die Summen des Zeitfensters.
 * Dieselbe Sichtbarkeit wie {@see RunRepository} ({@see ScopeSql::PREDICATE}); Testläufe zählen nicht. Liefert nie
 * Ausgabe, Notizen oder Worker-Daten.
 */
final class OverviewRepository
{
    public const USUAL_SAMPLES = 10;
    public const USUAL_MIN = 5;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array{job_id: int, bucket: int, ok: int, bad: int}>
     */
    public function buckets(CategoryScope $scope, int $fromTs, int $toTs, int $bucketSeconds): array
    {
        $rows = $this->db->fetchAll(
            "SELECT r.job_id AS job_id, (CAST(strftime('%s', r.started_at) AS INTEGER) - :from_ts) / :bucket AS bucket,
SUM(CASE WHEN r.status = 'ok' THEN 1 ELSE 0 END) AS ok,
SUM(CASE WHEN r.status IN ('failed', 'timeout', 'aborted') THEN 1 ELSE 0 END) AS bad
FROM runs r JOIN jobs j ON j.id = r.job_id LEFT JOIN categories c ON c.id = j.category_id
WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))
AND r.trigger <> 'test' AND r.started_at IS NOT NULL
AND CAST(strftime('%s', r.started_at) AS INTEGER) >= :from_ts AND CAST(strftime('%s', r.started_at) AS INTEGER) < :to_ts
GROUP BY r.job_id, bucket",
            $scope->sqlParameters() + ['from_ts' => $fromTs, 'to_ts' => $toTs, 'bucket' => $bucketSeconds],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['job_id' => Row::int($row, 'job_id'), 'bucket' => Row::int($row, 'bucket'), 'ok' => Row::int($row, 'ok'), 'bad' => Row::int($row, 'bad')];
        }

        return $out;
    }

    /**
     * @return array{runs: int, failed: int, avg_duration_ms: int|null}
     */
    public function totals(CategoryScope $scope, int $fromTs, int $toTs): array
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS n, SUM(CASE WHEN r.status IN ('failed', 'timeout', 'aborted') THEN 1 ELSE 0 END) AS bad,
CAST(ROUND(AVG(CASE WHEN r.status = 'ok' THEN r.duration_ms END)) AS INTEGER) AS avg_ms
FROM runs r JOIN jobs j ON j.id = r.job_id LEFT JOIN categories c ON c.id = j.category_id
WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))
AND r.trigger <> 'test' AND r.started_at IS NOT NULL
AND CAST(strftime('%s', r.started_at) AS INTEGER) >= :from_ts AND CAST(strftime('%s', r.started_at) AS INTEGER) < :to_ts",
            $scope->sqlParameters() + ['from_ts' => $fromTs, 'to_ts' => $toTs],
        );
        return [
            'runs' => $row === null ? 0 : Row::int($row, 'n'),
            'failed' => $row === null ? 0 : Row::intOrNull($row, 'bad') ?? 0,
            'avg_duration_ms' => $row === null ? null : Row::intOrNull($row, 'avg_ms'),
        ];
    }

    /**
     * Laufende Läufe mit dem üblichen Wert: Mittel der letzten {@see self::USUAL_SAMPLES} erfolgreichen Läufe des Jobs,
     * nur bei mindestens {@see self::USUAL_MIN} Messwerten. Dieselbe Sichtbarkeit wie die übrigen Abfragen; Testläufe zählen nicht.
     *
     * @return list<array{job_id: int, started_at: string, usual_ms: int}>
     */
    public function runningWithUsualDuration(CategoryScope $scope): array
    {
        $rows = $this->db->fetchAll(
            "SELECT r.job_id AS job_id, r.started_at AS started_at, a.avg_ms AS avg_ms
FROM runs r JOIN jobs j ON j.id = r.job_id LEFT JOIN categories c ON c.id = j.category_id
JOIN (SELECT job_id, CAST(AVG(duration_ms) AS INTEGER) AS avg_ms, COUNT(*) AS n FROM
        (SELECT job_id, duration_ms, ROW_NUMBER() OVER (PARTITION BY job_id ORDER BY id DESC) AS rn
           FROM runs WHERE status = 'ok' AND trigger <> 'test' AND duration_ms IS NOT NULL)
      WHERE rn <= :samples GROUP BY job_id) a ON a.job_id = r.job_id
WHERE r.status = 'running' AND r.trigger <> 'test' AND r.started_at IS NOT NULL AND a.n >= :min
AND (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))
ORDER BY r.started_at",
            $scope->sqlParameters() + ['samples' => self::USUAL_SAMPLES, 'min' => self::USUAL_MIN],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['job_id' => Row::int($row, 'job_id'), 'started_at' => Row::string($row, 'started_at'), 'usual_ms' => Row::int($row, 'avg_ms')];
        }

        return $out;
    }
}
