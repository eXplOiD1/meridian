<?php

declare(strict_types=1);

namespace Meridian\Job;

/**
 * Ergebnis von `POST /api/runs/{id}/cancel` (ADR 0004 E7).
 */
enum CancelOutcome
{
    /** Wartender Lauf: sofort `aborted` (200). */
    case AbortedBeforeStart;
    /** Laufender Lauf: Abbruch angefordert oder schon angefordert; der Worker bricht ab (202). */
    case Requested;
    /** Lauf schon beendet (409). */
    case AlreadyFinished;
}
