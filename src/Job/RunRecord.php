<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Runner\JobType;
use Meridian\Schedule\RunStatus;
use Meridian\Schedule\RunTrigger;

/**
 * Ein Lauf, wie ihn lesende Endpunkte sehen: nur Felder der Allowlist. `worker`, `heartbeat_at` und `exec_ref` (H2)
 * werden nie geladen. `output` ist nur im Detail gesetzt (bei der Verlaufsliste null).
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
        public ?int $exitCode = null,
        public ?string $cancelRequestedAt = null,
        public ?int $cancelledById = null,
        public ?string $cancelledByName = null,
        /** Gelesene Rohbytes der Ausgabe (auch der nicht gespeicherten). */
        public ?int $outputBytes = null,
        /** Es gibt Stücke im Live-Log (`run_log_chunks`). */
        public bool $live = false,
        /** Name des Jobs: nur für den Audit-Eintrag, nicht für die Antwort. */
        public string $jobName = '',
    ) {
    }
}
