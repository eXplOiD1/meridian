<?php

declare(strict_types=1);

namespace Meridian\Job;

/**
 * Ergebnis der Jobliste: höchstens {@see JobRepository::LIST_LIMIT} Jobs.
 */
final readonly class JobListing
{
    /**
     * @param list<JobRecord> $jobs
     */
    public function __construct(
        public array $jobs,
        public bool $truncated,
    ) {
    }
}
