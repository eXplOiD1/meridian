<?php

declare(strict_types=1);

namespace Meridian\Job;

/**
 * Das eine SQL-Prädikat für die Sichtbarkeit (docs/decisions/0003, E6): Jobs, Läufe und Kategorien kappen
 * ihre Ergebnisse damit auf den `CategoryScope` des Benutzers. Parameter liefert
 * {@see \Meridian\Security\CategoryScope::sqlParameters()}; die Tabelle `categories` hat den Alias `c`.
 *
 * Psalm erkennt zusammengesetzte Konstanten nicht zuverlässig als `literal-string`; deshalb steht das Prädikat
 * als identischer Text in jeder Abfrage der Repositories, und `ScopeSqlTest` prüft, dass keine davon abweicht.
 *
 * Ein Datensatz ohne Kategorie hat `c.name IS NULL` und fällt bei „nur diese Kategorien“ heraus (NULL IN … ist
 * nie wahr); bei „alle“ (`scope_all = 1`) bleibt er sichtbar.
 */
final class ScopeSql
{
    public const PREDICATE = '(:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))';

    private function __construct()
    {
    }
}
