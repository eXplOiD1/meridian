<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Auth\Clock;
use Meridian\Runner\Heartbeat;
use Meridian\Runner\StopReason;

/**
 * Nur für `scheduler:run --inline-worker` (Entwicklung, Tests): Läufe blockieren dort den Takt, also verlängert der
 * Herzschlag zusätzlich die Scheduler-Sperre (höchstens alle {@see RunHeartbeat::MIN_WRITE_SECONDS} Sekunden).
 * Im Betrieb berühren Worker die Sperre nie (ADR 0004 E5).
 */
final class LeaseHeartbeat implements Heartbeat
{
    private ?float $lastExtend = null;

    public function __construct(
        private readonly Heartbeat $inner,
        private readonly SchedulerLease $lease,
        private readonly Clock $clock,
    ) {
    }

    #[\Override]
    public function beat(): bool
    {
        $alive = $this->inner->beat();
        $at = (float) $this->clock->now()->format('U.u');
        if ($alive && ($this->lastExtend === null || $at - $this->lastExtend >= RunHeartbeat::MIN_WRITE_SECONDS)) {
            try {
                $this->lease->acquire();
                $this->lastExtend = $at;
            } catch (\Throwable) {
                // Wie beim Herzschlag: ein Datenbankfehler ist kein Abbruch.
            }
        }

        return $alive;
    }

    #[\Override]
    public function stopReason(): ?StopReason
    {
        return $this->inner->stopReason();
    }
}
