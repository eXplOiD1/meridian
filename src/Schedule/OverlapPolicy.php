<?php

declare(strict_types=1);

namespace Meridian\Schedule;

/**
 * Verhalten, wenn ein Termin fällig wird, während der Job noch läuft oder wartet (jobs.overlap_policy).
 */
enum OverlapPolicy: string
{
    /** Neuer Termin entfällt, vermerkt als Lauf mit Status „skipped“. */
    case Skip = 'skip';
    /** Neuer Lauf startet unabhängig vom laufenden (Warteschlange begrenzt). */
    case Parallel = 'parallel';
    /** Neuer Lauf wartet, bis der laufende fertig ist; höchstens einer wartet. */
    case Queue = 'queue';
}
