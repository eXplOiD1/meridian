<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Runner\JobType;
use Meridian\Schedule\RunStatus;
use Meridian\Schedule\RunTrigger;

/**
 * Ein Lauf, wie ihn lesende Endpunkte sehen: nur Felder der Allowlist. `worker` und `heartbeat_at` (H2) werden
 * nie geladen. `output` ist nur im Detail gesetzt (bei der Verlaufsliste null).
 */
final readonly class RunRecord
{
    public function __construct(
        public int $id,
        public int $jobId,
        public RunTrigger $trigger,
        public RunStatus $status,
        public int $attempt,
        public ?string $scheduledFor,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?int $durationMs,
        public ?int $httpStatus,
        public ?string $note,
        public ?string $output,
        public ?int $startedById,
        public ?string $startedByName,
        /** Kategorie und Typ des Jobs: für die Rechteprüfung, nicht für die Antwort. */
        public ?string $categoryName,
        public JobType $jobType,
    ) {
    }
}
