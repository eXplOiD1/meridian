<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Runner\Heartbeat;

/**
 * Herzschlag eines Laufs: schreibt runs.heartbeat_at und verlängert die Scheduler-Sperre. Häufige Aufrufe
 * werden gedrosselt (höchstens ein Schreibzugriff je {@see self::MIN_WRITE_SECONDS}).
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

        $this->lease->acquire();
        $this->alive = $this->db->execute(
            "UPDATE runs SET heartbeat_at = :now WHERE id = :id AND status = 'running' AND worker = :me",
            ['now' => Timestamp::format($now), 'id' => $this->runId, 'me' => $this->lease->owner()],
        ) === 1;
        $this->lastWrite = $now;

        return $this->alive;
    }
}
