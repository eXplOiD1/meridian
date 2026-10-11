<?php

declare(strict_types=1);

namespace Meridian\Schedule;

/**
 * H1: Darf der auslösende Benutzer diesen Job (noch) starten? Gefragt beim Übernehmen eines manuellen Laufs,
 * eines Testlaufs und einer Wiederholung mit auslösendem Benutzer — zwischen Einreihen und Ausführen können
 * Benutzer deaktiviert, Rechte entzogen oder der Job verschoben worden sein.
 *
 * Frische Rechte, `is_active`, Kategorie des Jobs aus der Datenbank. Wirft nie: Im Zweifel (Fehler, unbekannt)
 * false.
 */
interface RunAuthorizer
{
    public function mayStart(int $userId, int $jobId): bool;
}
