<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Runner\Http\HttpPayload;
use Meridian\Runner\JobType;
use Meridian\Schedule\OverlapPolicy;

/**
 * Ein geprüfter Job, bereit zum Speichern. Das Ergebnis von {@see JobValidator}; alle Werte sind validiert.
 * `payload` ist null, wenn beim Ändern die Anfrage nicht ersetzt wird (die gespeicherte bleibt unberührt).
 */
final readonly class JobDraft
{
    public function __construct(
        public string $name,
        public JobType $type,
        public ?int $categoryId,
        /** Name der Kategorie für die Rechteprüfung (null = ohne Kategorie). */
        public ?string $categoryName,
        public string $cron,
        public string $timezone,
        public bool $isEnabled,
        public bool $catchUp,
        public OverlapPolicy $overlapPolicy,
        public int $retryCount,
        public int $retryDelaySeconds,
        public HttpJobConfig $http,
        public ?HttpPayload $payload,
    ) {
    }
}
