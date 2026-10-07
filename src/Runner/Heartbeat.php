<?php

declare(strict_types=1);

namespace Meridian\Runner;

/**
 * Lebenszeichen eines laufenden Laufs. Ein Runner ruft beat() regelmäßig auf (mindestens alle
 * {@see self::MAX_INTERVAL_SECONDS} Sekunden), auch während er wartet. Das hält den Lauf als lebend
 * markiert und verlängert die Scheduler-Sperre.
 *
 * false heißt: der Lauf gehört nicht mehr diesem Prozess (als hängend abgebrochen). Der Runner soll dann
 * so schnell wie möglich aufhören; sein Ergebnis wird nicht mehr gespeichert.
 */
interface Heartbeat
{
    public const int MAX_INTERVAL_SECONDS = 20;

    public function beat(): bool;
}
