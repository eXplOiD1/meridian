<?php

declare(strict_types=1);

namespace Meridian\Runner;

use Meridian\Schedule\RunTrigger;

/**
 * Auftrag an einen Runner. Enthält keine Payload: der Runner lädt und entschlüsselt sie selbst
 * (und registriert sie im SecretMasker des Laufs), nur dort, wo sie gebraucht wird.
 */
final readonly class RunRequest
{
    /**
     * @param int|null $startedBy auslösender Benutzer (manueller Lauf, Testlauf und deren Wiederholungen), sonst null
     */
    public function __construct(
        public int $runId,
        public int $jobId,
        public JobType $type,
        public RunTrigger $trigger,
        public int $attempt,
        public ?int $startedBy = null,
    ) {
    }
}
