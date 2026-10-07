<?php

declare(strict_types=1);

namespace Meridian\Runner;

/**
 * Job-Typ (Spalte jobs.type).
 */
enum JobType: string
{
    case Http = 'http';
    case Shell = 'shell';
}
