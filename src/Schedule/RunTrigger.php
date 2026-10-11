<?php

declare(strict_types=1);

namespace Meridian\Schedule;

/**
 * Auslöser eines Laufs (Spalte runs.trigger).
 */
enum RunTrigger: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Retry = 'retry';
    case Test = 'test';
}
