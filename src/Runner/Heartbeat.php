<?php

declare(strict_types=1);

namespace Meridian\Runner;

/**
 * Lebenszeichen eines laufenden Laufs. Ein Runner ruft beat() regelmäßig auf (mindestens alle
 * {@see self::MAX_INTERVAL_SECONDS} Sekunden, besser jede Sekunde), auch während er wartet. Das hält den Lauf als
 * lebend markiert; die Umsetzung des Workers liest dabei höchstens jede Sekunde, ob der Lauf abgebrochen wurde.
 *
 * false heißt: sofort aufhören. Den Grund nennt {@see self::stopReason()}: als hängend abgebrochen (das Ergebnis wird
 * nicht mehr gespeichert), Abbruch durch einen Benutzer oder Worker wird beendet (beides speichert der Worker als
 * „aborted“, ohne Wiederholung). Ist false einmal geliefert, bleibt es dabei.
 *
 * beat() wirft nie. Ein Datenbankfehler beim Schreiben ist kein Abbruch: dann liefert beat() true und der
 * nächste Aufruf schreibt erneut. Nur ein bestätigter Abbruch liefert false.
 */
interface Heartbeat
{
    public const int MAX_INTERVAL_SECONDS = 20;

    /**
     * Wirft nie (siehe oben). Hat Wirkung (schreibt den Herzschlag) und kann bei jedem Aufruf anders ausfallen.
     *
     * @phpstan-impure
     */
    public function beat(): bool;

    /**
     * Grund des letzten `false` von {@see self::beat()}, sonst null.
     */
    public function stopReason(): ?StopReason;
}
