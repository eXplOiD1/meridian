<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Database\Connection;
use Meridian\Security\CategoryScope;

/**
 * Statistik über Tage und Jobs (ADR 0008): frische Läufe aus `runs` plus die Tageswerte aus `run_stats_daily`
 * ({@see RunRetention}); beides auf dieselbe Sichtbarkeit gekappt wie Liste und Verlauf. Gezählt werden beendete Läufe
 * (ok, fehlgeschlagen, Zeitlimit, abgebrochen), nie Testläufe und übersprungene Termine. Ein Tag, der teilweise schon
 * zusammengefasst ist, wird richtig addiert, weil ein Lauf nie in beiden Tabellen steht.
 */
final class StatsRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array<string, array{runs: int, ok: int, failed: int, duration_ms: int}> Tag (UTC) → Summen
     */
    public function daily(CategoryScope $scope, string $fromDay, ?int $jobId): array
    {
        $params = $scope->sqlParameters() + ['job' => $jobId];
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT substr(r.finished_at, 1, 10) AS d, COUNT(*) AS n, SUM(r.status = 'ok') AS ok, SUM(r.status <> 'ok') AS bad, COALESCE(SUM(r.duration_ms), 0) AS dur
               FROM runs r JOIN jobs j ON j.id = r.job_id LEFT JOIN categories c ON c.id = j.category_id
              WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names))) AND (:job IS NULL OR r.job_id = :job)
                AND r.trigger <> 'test' AND r.status IN ('ok', 'failed', 'timeout', 'aborted') AND r.finished_at >= :from GROUP BY d",
            $params + ['from' => $fromDay . 'T00:00:00+00:00'],
        ) as $row) {
            $out[Row::string($row, 'd')] = ['runs' => Row::int($row, 'n'), 'ok' => Row::int($row, 'ok'), 'failed' => Row::int($row, 'bad'), 'duration_ms' => Row::int($row, 'dur')];
        }
        foreach ($this->db->fetchAll(
            'SELECT s.day AS d, SUM(s.ok + s.failed + s.timeout + s.aborted) AS n, SUM(s.ok) AS ok, SUM(s.failed + s.timeout + s.aborted) AS bad, SUM(s.duration_ms_total) AS dur
               FROM run_stats_daily s JOIN jobs j ON j.id = s.job_id LEFT JOIN categories c ON c.id = j.category_id
              WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names))) AND (:job IS NULL OR s.job_id = :job) AND s.day >= :day GROUP BY d',
            $params + ['day' => $fromDay],
        ) as $row) {
            $day = Row::string($row, 'd');
            $base = $out[$day] ?? ['runs' => 0, 'ok' => 0, 'failed' => 0, 'duration_ms' => 0];
            $out[$day] = ['runs' => $base['runs'] + Row::int($row, 'n'), 'ok' => $base['ok'] + Row::int($row, 'ok'), 'failed' => $base['failed'] + Row::int($row, 'bad'), 'duration_ms' => $base['duration_ms'] + Row::int($row, 'dur')];
        }

        return $out;
    }

    /**
     * @return array<int, array{name: string, runs: int, ok: int, failed: int, duration_ms: int, max_ms: int}> Job-ID → Summen
     */
    public function perJob(CategoryScope $scope, string $fromDay): array
    {
        $params = $scope->sqlParameters();
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT j.id AS id, j.name AS name, COUNT(*) AS n, SUM(r.status = 'ok') AS ok, SUM(r.status <> 'ok') AS bad, COALESCE(SUM(r.duration_ms), 0) AS dur, COALESCE(MAX(r.duration_ms), 0) AS mx
               FROM runs r JOIN jobs j ON j.id = r.job_id LEFT JOIN categories c ON c.id = j.category_id
              WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names))) AND r.trigger <> 'test'
                AND r.status IN ('ok', 'failed', 'timeout', 'aborted') AND r.finished_at >= :from GROUP BY j.id",
            $params + ['from' => $fromDay . 'T00:00:00+00:00'],
        ) as $row) {
            $out[Row::int($row, 'id')] = ['name' => Row::string($row, 'name'), 'runs' => Row::int($row, 'n'), 'ok' => Row::int($row, 'ok'), 'failed' => Row::int($row, 'bad'), 'duration_ms' => Row::int($row, 'dur'), 'max_ms' => Row::int($row, 'mx')];
        }
        foreach ($this->db->fetchAll(
            'SELECT j.id AS id, j.name AS name, SUM(s.ok + s.failed + s.timeout + s.aborted) AS n, SUM(s.ok) AS ok, SUM(s.failed + s.timeout + s.aborted) AS bad, SUM(s.duration_ms_total) AS dur, MAX(s.duration_ms_max) AS mx
               FROM run_stats_daily s JOIN jobs j ON j.id = s.job_id LEFT JOIN categories c ON c.id = j.category_id
              WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names))) AND s.day >= :day GROUP BY j.id',
            $params + ['day' => $fromDay],
        ) as $row) {
            $id = Row::int($row, 'id');
            $base = $out[$id] ?? ['name' => Row::string($row, 'name'), 'runs' => 0, 'ok' => 0, 'failed' => 0, 'duration_ms' => 0, 'max_ms' => 0];
            $out[$id] = ['name' => $base['name'], 'runs' => $base['runs'] + Row::int($row, 'n'), 'ok' => $base['ok'] + Row::int($row, 'ok'), 'failed' => $base['failed'] + Row::int($row, 'bad'), 'duration_ms' => $base['duration_ms'] + Row::int($row, 'dur'), 'max_ms' => max($base['max_ms'], Row::int($row, 'mx'))];
        }

        return $out;
    }
}
