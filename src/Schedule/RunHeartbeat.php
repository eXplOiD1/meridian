<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Runner\Heartbeat;

/**
 * Herzschlag eines Laufs: schreibt runs.heartbeat_at und verlängert die Scheduler-Sperre. Häufige Aufrufe
 * werden gedrosselt (höchstens ein Schreibzugriff je {@see self::MIN_WRITE_SECONDS}). Wirft nie: ein Datenbankfehler
 * gilt nicht als Abbruch.
 */
final class RunHeartbeat implements Heartbeat
{
    public const MIN_WRITE_SECONDS = 5;

    private ?\DateTimeImmutable $lastWrite = null;

    private bool $alive = true;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SchedulerLease $lease,
        private readonly int $runId,
    ) {
    }

    #[\Override]
    public function beat(): bool
    {
        if (!$this->alive) {
            return false;
        }
        $now = $this->clock->now();
        if ($this->lastWrite !== null && $now->getTimestamp() - $this->lastWrite->getTimestamp() < self::MIN_WRITE_SECONDS) {
            return true;
        }

        try {
            $this->lease->acquire();
            $changed = $this->db->execute(
                "UPDATE runs SET heartbeat_at = :now WHERE id = :id AND status = 'running' AND worker = :me",
                ['now' => Timestamp::format($now), 'id' => $this->runId, 'me' => $this->lease->owner()],
            );
        } catch (\Throwable) {
            // Datenbank kurz gesperrt oder E/A-Fehler: kein Beweis, dass der Lauf abgebrochen wurde. Weiterlaufen
            // lassen (sonst liefe die Arbeit doppelt), nichts weitergeben; der nächste Aufruf schreibt erneut.
            return true;
        }
        // Nur ein bestätigtes „keine Zeile“ heißt: der Lauf gehört nicht mehr diesem Prozess.
        $this->alive = $changed === 1;
        $this->lastWrite = $now;

        return $this->alive;
    }
}
