<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Runner\JobType;

/**
 * Kennung eines Worker-Prozesses (ADR 0004 E5): steht in `workers.id` und `runs.worker`. Je Prozess neu (Format wie
 * {@see SchedulerLease::newOwnerId()}), damit ein Neustart nie fremde Läufe „wiedererkennt“. Kein Geheimnis.
 */
final readonly class WorkerIdentity
{
    public function __construct(
        public string $id,
        public JobType $kind,
    ) {
        if ($id === '' || strlen($id) > 200) {
            throw new \InvalidArgumentException('Worker-Kennung muss 1 bis 200 Zeichen haben.');
        }
    }

    public static function generate(JobType $kind): self
    {
        return new self(SchedulerLease::newOwnerId(), $kind);
    }
}
