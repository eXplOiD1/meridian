<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Settings\Settings;

/**
 * Aufbewahrung des Verlaufs (ADR 0008): beendete Läufe, die älter sind als `runs.retention_days`, werden zu Tageswerten je
 * Job (UTC, `run_stats_daily`) zusammengefasst und gelöscht; Ausgabe und Live-Log gehen per ON DELETE CASCADE mit. Testläufe
 * zählen nicht in die Tageswerte. Wartende und laufende Läufe werden nie angefasst.
 *
 * Zusammenfassen und Löschen stehen je Stapel in **einer** Transaktion: ein Lauf ist nie zugleich gelöscht und nicht gezählt
 * oder doppelt gezählt. Läuft im Planer-Prozess unter der Scheduler-Sperre, höchstens alle {@see self::EVERY_SECONDS} s.
 */
final class RunRetention
{
    public const BATCH = 1000;
    public const EVERY_SECONDS = 600;
    private const MAX_BATCHES_PER_PASS = 20;

    private float $lastRun = 0.0;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Settings $settings,
    ) {
    }

    /**
     * Läuft nur, wenn die letzte Ausführung lange genug her ist (oder noch keine war).
     *
     * @return int Anzahl gelöschter Läufe (0, wenn nichts zu tun war oder es noch zu früh ist)
     */
    public function maybeRun(): int
    {
        $now = microtime(true);
        if ($this->lastRun !== 0.0 && $now - $this->lastRun < self::EVERY_SECONDS) {
            return 0;
        }
        $this->lastRun = $now;

        return $this->run();
    }

    /**
     * @return int Anzahl gelöschter Läufe
     */
    public function run(): int
    {
        $cutoff = Timestamp::format($this->clock->now()->modify('-' . $this->settings->runRetentionDays() . ' days'));
        $deleted = 0;
        for ($i = 0; $i < self::MAX_BATCHES_PER_PASS; ++$i) {
            $count = $this->db->immediate(fn (): int => $this->batch($cutoff));
            $deleted += $count;
            if ($count < self::BATCH) {
                break;
            }
        }

        return $deleted;
    }

    private function batch(string $cutoff): int
    {
        $rows = $this->db->fetchAll(
            "SELECT id FROM runs WHERE finished_at IS NOT NULL AND finished_at < :cutoff AND status NOT IN ('queued', 'running') ORDER BY id LIMIT :limit",
            ['cutoff' => $cutoff, 'limit' => self::BATCH],
        );
        if ($rows === []) {
            return 0;
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = Row::int($row, 'id');
        }
        $json = json_encode($ids, JSON_THROW_ON_ERROR);

        // Unter 1000 Zeichen halten: längere Konstanten sieht Psalm nicht mehr als literal-string.
        $this->db->execute(
            "INSERT INTO run_stats_daily (job_id, day, runs, ok, failed, timeout, aborted, skipped, duration_ms_total, duration_ms_max)
             SELECT job_id, substr(finished_at, 1, 10) AS d, COUNT(*), SUM(status = 'ok'), SUM(status = 'failed'), SUM(status = 'timeout'),
                    SUM(status = 'aborted'), SUM(status = 'skipped'), COALESCE(SUM(duration_ms), 0), COALESCE(MAX(duration_ms), 0)
               FROM runs WHERE id IN (SELECT value FROM json_each(:ids)) AND trigger <> 'test' GROUP BY job_id, d
             ON CONFLICT (job_id, day) DO UPDATE SET runs = runs + excluded.runs, ok = ok + excluded.ok, failed = failed + excluded.failed,
                    timeout = timeout + excluded.timeout, aborted = aborted + excluded.aborted, skipped = skipped + excluded.skipped,
                    duration_ms_total = duration_ms_total + excluded.duration_ms_total, duration_ms_max = MAX(duration_ms_max, excluded.duration_ms_max)",
            ['ids' => $json],
        );
        $this->db->execute('DELETE FROM runs WHERE id IN (SELECT value FROM json_each(:ids))', ['ids' => $json]);

        return count($ids);
    }
}
