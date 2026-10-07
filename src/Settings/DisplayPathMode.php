<?php

declare(strict_types=1);

namespace Meridian\Settings;

/**
 * Einstellung `http.display_path`: wie viel vom Pfad und der Query die Anzeige-URL eines HTTP-Jobs zeigt
 * (docs/decisions/0003, E2/E10).
 */
enum DisplayPathMode: string
{
    /** Harmlose Pfadwörter und Query-Namen sichtbar, alles andere `••••`. */
    case Auto = 'auto';
    /** Pfad immer `/••••`, Query immer `?••••`. */
    case Hidden = 'hidden';

    /** Standard ohne Zeile in `settings` (R1, Entscheidung Alex 07.10.2026: `auto`). */
    public static function default(): self
    {
        return self::Auto;
    }
}
