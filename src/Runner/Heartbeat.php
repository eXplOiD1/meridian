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
 *
 * beat() wirft nie. Ein Datenbankfehler beim Schreiben ist kein Abbruch: dann liefert beat() true und der
 * nächste Aufruf schreibt erneut. Nur ein bestätigter Abbruch liefert false.
 */
interface Heartbeat
{
    public const int MAX_INTERVAL_SECONDS = 20;

    /**
     * Wirft nie (siehe oben). Hat Wirkung (schreibt, verlängert die Sperre) und kann bei jedem Aufruf anders
     * ausfallen.
     *
     * @phpstan-impure
     */
    public function beat(): bool;
}
