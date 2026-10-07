<?php

declare(strict_types=1);

namespace Meridian\Schedule;

/**
 * Zustand eines Laufs (Spalte runs.status, siehe CHECK in migrations/0005_scheduler.sql).
 */
enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Ok = 'ok';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case Aborted = 'aborted';
    case Skipped = 'skipped';

    /** Nur Fehler und Zeitüberschreitung werden wiederholt, nie Abbruch oder Überspringen. */
    public function isRetryable(): bool
    {
        return $this === self::Failed || $this === self::Timeout;
    }
}
