<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Runner\JobType;

/**
 * Aufräumen im Planer-Takt (ADR 0004 E5, E8.6, §3): nur mit gehaltener Sperre, jeweils in einer `immediate()`-
 * Transaktion, die die Sperre zuerst prüft. Worker räumen nie selbst auf.
 *
 *  - Laufende Läufe ohne frischen Herzschlag (älter als {@see SchedulerLease::TTL_SECONDS}) → „aborted“, nie neu
 *    gestartet (Absturz eines Workers, Speicherfehler).
 *  - Wartende Läufe ohne Job oder mit unbekanntem Typ → „aborted“.
 *  - Wartende Läufe eines Typs, für den seit {@see self::NO_WORKER_SECONDS} kein Worker `seen_at` hat, und die seit
 *    mindestens so lange fällig sind → „aborted“ mit Hinweis auf den fehlenden Dienst.
 *  - Live-Log-Stücke 15 min nach Laufende, abgelaufene Live-Verbindungen und Worker-Zeilen älter als 1 h löschen
 *    (Stapel zu {@see self::BATCH}).
 */
final class StaleRuns
{
    public const NO_WORKER_SECONDS = 600;
    public const LOG_RETENTION_SECONDS = 900;
    public const WORKER_ROW_RETENTION_SECONDS = 3600;
    public const BATCH = 500;

    public const NOTE_NO_HTTP_WORKER = 'Abgebrochen: Kein Worker für diesen Job-Typ aktiv: Dienst worker-http starten (worker:run --type=http).';
    public const NOTE_NO_SHELL_WORKER = 'Abgebrochen: Kein Worker für diesen Job-Typ aktiv: Dienst worker-shell starten (worker:run --type=shell).';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SchedulerLease $lease,
    ) {
    }

    /**
     * @return list<RunEvent>
     */
    public function abort(): array
    {
        return $this->db->immediate(function (): array {
            if (!$this->lease->isHeld()) {
                return [];
            }
            $now = $this->clock->now();
            $nowText = Timestamp::format($now);
            $events = [];

            $heartbeatCutoff = Timestamp::format($now->modify('-' . SchedulerLease::TTL_SECONDS . ' seconds'));
            foreach ($this->db->fetchAll(
                "SELECT id, job_id FROM runs WHERE status = 'running' AND COALESCE(heartbeat_at, started_at, '') <= :cutoff ORDER BY id LIMIT 500",
                ['cutoff' => $heartbeatCutoff],
            ) as $row) {
                if (is_int($row['id']) && is_int($row['job_id']) && $this->db->execute(
                    "UPDATE runs SET status = 'aborted', finished_at = :now, note = :note WHERE id = :id AND status = 'running' AND COALESCE(heartbeat_at, started_at, '') <= :cutoff",
                    ['now' => $nowText, 'note' => Worker::NOTE_STALE, 'id' => $row['id'], 'cutoff' => $heartbeatCutoff],
                ) === 1) {
                    $events[] = new RunEvent($row['job_id'], $row['id'], RunStatus::Aborted);
                }
            }

            foreach ($this->db->fetchAll(
                "SELECT r.id, r.job_id FROM runs r LEFT JOIN jobs j ON j.id = r.job_id WHERE r.status = 'queued' AND (j.id IS NULL OR j.type NOT IN ('http', 'shell')) ORDER BY r.id LIMIT 500",
            ) as $row) {
                $events = [...$events, ...$this->closeQueued($row, Worker::NOTE_JOB_INVALID, $nowText)];
            }

            $noWorkerCutoff = Timestamp::format($now->modify('-' . self::NO_WORKER_SECONDS . ' seconds'));
            foreach (JobType::cases() as $type) {
                $alive = $this->db->fetchOne(
                    'SELECT 1 AS alive FROM workers WHERE kind = :kind AND seen_at > :cutoff LIMIT 1',
                    ['kind' => $type->value, 'cutoff' => $noWorkerCutoff],
                );
                if ($alive !== null) {
                    continue;
                }
                $note = match ($type) {
                    JobType::Http => self::NOTE_NO_HTTP_WORKER,
                    JobType::Shell => self::NOTE_NO_SHELL_WORKER,
                };
                // Nur Läufe, die schon so lange fällig sind: ein frisch eingereihter Lauf wartet, bis ein Worker kommt.
                foreach ($this->db->fetchAll(
                    "SELECT r.id, r.job_id FROM runs r JOIN jobs j ON j.id = r.job_id WHERE r.status = 'queued' AND j.type = :type AND r.scheduled_for IS NOT NULL AND r.scheduled_for <= :cutoff ORDER BY r.id LIMIT 500",
                    ['type' => $type->value, 'cutoff' => $noWorkerCutoff],
                ) as $row) {
                    $events = [...$events, ...$this->closeQueued($row, $note, $nowText)];
                }
            }

            return $events;
        });
    }

    /**
     * Typen mit fälligen wartenden Läufen (seit mindestens `$seconds`), für die gerade kein Worker lebt
     * (`seen_at` jünger als `$seconds`). Nur für eine Warnung des Planers (keine Änderung).
     *
     * @return list<JobType>
     */
    public function typesWaitingWithoutWorker(int $seconds = 60): array
    {
        $cutoff = Timestamp::format($this->clock->now()->modify('-' . $seconds . ' seconds'));
        $types = [];
        foreach (JobType::cases() as $type) {
            $waiting = $this->db->fetchOne(
                "SELECT 1 AS waiting FROM runs r JOIN jobs j ON j.id = r.job_id WHERE r.status = 'queued' AND j.type = :type AND r.scheduled_for IS NOT NULL AND r.scheduled_for <= :cutoff
                    AND NOT EXISTS (SELECT 1 FROM workers w WHERE w.kind = :type AND w.seen_at > :cutoff) LIMIT 1",
                ['type' => $type->value, 'cutoff' => $cutoff],
            );
            if ($waiting !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Löscht Live-Log-Stücke beendeter Läufe, abgelaufene Live-Verbindungen und alte Worker-Zeilen.
     */
    public function cleanup(): void
    {
        $this->db->immediate(function (): void {
            if (!$this->lease->isHeld()) {
                return;
            }
            $now = $this->clock->now();
            $this->db->execute(
                "DELETE FROM run_log_chunks WHERE id IN (SELECT c.id FROM run_log_chunks c JOIN runs r ON r.id = c.run_id WHERE r.status NOT IN ('queued', 'running') AND r.finished_at IS NOT NULL AND r.finished_at <= :cutoff LIMIT 500)",
                ['cutoff' => Timestamp::format($now->modify('-' . self::LOG_RETENTION_SECONDS . ' seconds'))],
            );
            $this->db->execute('DELETE FROM live_streams WHERE expires_at <= :now', ['now' => Timestamp::format($now)]);
            $this->db->execute(
                'DELETE FROM workers WHERE seen_at <= :cutoff',
                ['cutoff' => Timestamp::format($now->modify('-' . self::WORKER_ROW_RETENTION_SECONDS . ' seconds'))],
            );
        });
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<RunEvent>
     */
    private function closeQueued(array $row, string $note, string $now): array
    {
        $id = $row['id'] ?? null;
        $jobId = $row['job_id'] ?? null;
        if (!is_int($id) || !is_int($jobId)) {
            return [];
        }
        $changed = $this->db->execute(
            "UPDATE runs SET status = 'aborted', finished_at = :now, note = :note WHERE id = :id AND status = 'queued'",
            ['now' => $now, 'note' => $note, 'id' => $id],
        );

        return $changed === 1 ? [new RunEvent($jobId, $id, RunStatus::Aborted)] : [];
    }
}
