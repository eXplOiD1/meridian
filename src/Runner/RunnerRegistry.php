<?php

declare(strict_types=1);

namespace Meridian\Runner;

/**
 * Runner je Job-Typ. Ein Typ ohne Runner wird nicht ausgeführt, sondern als fehlgeschlagen vermerkt.
 */
final class RunnerRegistry
{
    /** @var array<string, Runner> */
    private array $runners = [];

    public function register(JobType $type, Runner $runner): void
    {
        $this->runners[$type->value] = $runner;
    }

    public function for(JobType $type): ?Runner
    {
        return $this->runners[$type->value] ?? null;
    }
}
