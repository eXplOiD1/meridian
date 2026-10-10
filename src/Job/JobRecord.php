<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Runner\JobType;
use Meridian\Runner\Shell\ShellJobConfig;
use Meridian\Schedule\OverlapPolicy;

/**
 * Ein Job, wie ihn lesende Endpunkte sehen. `payload_enc` wird nie geladen: die Web-API entschlüsselt nie
 * (docs/decisions/0003, E3, 0004 E6). Die Kategorie stammt aus dem gespeicherten Datensatz.
 */
final readonly class JobRecord
{
    public function __construct(
        public int $id,
        public string $name,
        public JobType $type,
        public ?int $categoryId,
        public ?string $categoryName,
        public ?int $ownerId,
        public ?string $ownerName,
        public string $cron,
        public string $timezone,
        public ?string $nextRunAt,
        public bool $isEnabled,
        public OverlapPolicy $overlapPolicy,
        public int $retryCount,
        public int $retryDelaySeconds,
        public bool $catchUp,
        /** null bei Shell-Jobs und bei unlesbarer `config_json`. */
        public ?HttpJobConfig $http,
        /** null bei HTTP-Jobs und bei unlesbarer `config_json`. */
        public ?ShellJobConfig $shell,
        public ?LastRun $lastRun,
        public bool $running,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
