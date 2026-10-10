<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Runner\Heartbeat;
use Meridian\Runner\StopReason;

/**
 * Herzschlag eines Laufs im Worker (ADR 0004 E5): liest höchstens jede {@see self::READ_INTERVAL_SECONDS} Sekunde
 * `status`, `worker` und `cancel_requested_at` des Laufs, schreibt `runs.heartbeat_at` (und `workers.seen_at`)
 * höchstens alle {@see self::MIN_WRITE_SECONDS} Sekunden. Berührt die Scheduler-Sperre nie (das tut nur der Planer,
 * im Entwicklungsmodus zusätzlich {@see LeaseHeartbeat}).
 *
 * Wirft nie: Ein Datenbankfehler gilt nicht als Abbruch (weiterlaufen lassen, sonst liefe die Arbeit doppelt).
 * Nur ein bestätigter Befund liefert false: Lauf nicht mehr „running“ oder nicht mehr dieses Workers → Stale,
 * Abbruch angefordert → Cancelled, Worker wird beendet → WorkerStopping.
 */
final class RunHeartbeat implements Heartbeat
{
    public const MIN_WRITE_SECONDS = 5;
    public const READ_INTERVAL_SECONDS = 1.0;

    private ?float $lastWrite = null;
    private ?float $lastRead = null;
    private ?StopReason $reason = null;

    /**
     * @param (\Closure(): bool)|null $stopRequested true → der Worker wird beendet (SIGTERM)
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly string $workerId,
        private readonly int $runId,
        private readonly ?\Closure $stopRequested = null,
    ) {
    }

    #[\Override]
    public function beat(): bool
    {
        if ($this->reason !== null) {
            return false;
        }
        if ($this->stopRequested !== null && ($this->stopRequested)()) {
            $this->reason = StopReason::WorkerStopping;

            return false;
        }
        $now = $this->clock->now();
        $at = (float) $now->format('U.u');

        if ($this->lastRead === null || $at - $this->lastRead >= self::READ_INTERVAL_SECONDS) {
            try {
                $row = $this->db->fetchOne(
                    'SELECT status, worker, cancel_requested_at FROM runs WHERE id = :id',
                    ['id' => $this->runId],
                );
            } catch (\Throwable) {
                return true;
            }
            $this->lastRead = $at;
            if ($row === null || $row['status'] !== RunStatus::Running->value || $row['worker'] !== $this->workerId) {
                $this->reason = StopReason::Stale;

                return false;
            }
            if ($row['cancel_requested_at'] !== null) {
                $this->reason = StopReason::Cancelled;

                return false;
            }
        }

        if ($this->lastWrite !== null && $at - $this->lastWrite < self::MIN_WRITE_SECONDS) {
            return true;
        }
        try {
            $stamp = Timestamp::format($now);
            $changed = $this->db->execute(
                "UPDATE runs SET heartbeat_at = :now WHERE id = :id AND status = 'running' AND worker = :me",
                ['now' => $stamp, 'id' => $this->runId, 'me' => $this->workerId],
            );
            // Ein beschäftigter Worker lebt: sonst hielte der Planer seinen Typ nach 10 min für verwaist.
            $this->db->execute('UPDATE workers SET seen_at = :now WHERE id = :me', ['now' => $stamp, 'me' => $this->workerId]);
        } catch (\Throwable) {
            // Datenbank kurz gesperrt oder E/A-Fehler: kein Beweis für einen Abbruch, der nächste Aufruf schreibt erneut.
            return true;
        }
        if ($changed !== 1) {
            $this->reason = StopReason::Stale;

            return false;
        }
        $this->lastWrite = $at;

        return true;
    }

    #[\Override]
    public function stopReason(): ?StopReason
    {
        return $this->reason;
    }
}
