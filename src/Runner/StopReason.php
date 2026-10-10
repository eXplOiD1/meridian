<?php

declare(strict_types=1);

namespace Meridian\Runner;

/**
 * Warum ein Lauf aufhören soll ({@see Heartbeat::stopReason()}, ADR 0004 §5.1):
 *  - Stale: der Lauf gehört nicht mehr diesem Worker (als hängend abgebrochen, gelöscht); nichts mehr speichern.
 *  - Cancelled: ein Benutzer hat den Abbruch angefordert (`runs.cancel_requested_at`).
 *  - WorkerStopping: der Worker-Prozess wird beendet (SIGTERM, Neustart, Update).
 */
enum StopReason: string
{
    case Stale = 'stale';
    case Cancelled = 'cancelled';
    case WorkerStopping = 'worker_stopping';
}
