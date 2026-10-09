<?php

declare(strict_types=1);

namespace Meridian\Settings;

/**
 * Einstellung `http.display_host`: ob die API den Host eines HTTP-Jobs zeigt (`target` in Liste, Detail und
 * Übersicht sowie in `display_url`; docs/decisions/0003, E2/E10).
 */
enum DisplayHostMode: string
{
    /** Schema, Host und Port sichtbar. */
    case Auto = 'auto';
    /** Host und Port immer `••••` (`https://••••`), für niemanden sichtbar außer bei der Eingabe. */
    case Hidden = 'hidden';

    /** Standard ohne Zeile in `settings` (Entscheidung Alex 09.10.2026: `auto`). */
    public static function default(): self
    {
        return self::Auto;
    }
}
