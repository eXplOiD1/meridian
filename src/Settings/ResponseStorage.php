<?php

declare(strict_types=1);

namespace Meridian\Settings;

/**
 * Einstellung `http.response_storage`: ob die Antwort eines HTTP-Laufs im Verlauf gespeichert wird
 * (docs/decisions/0003, E11). Je Job gilt `inherit`/`on`/`off`; `never` gewinnt immer.
 */
enum ResponseStorage: string
{
    /** Job-Wert `inherit` speichert nicht. */
    case Off = 'off';
    /** Job-Wert `inherit` speichert. */
    case On = 'on';
    /** Erzwingt „aus“; der Runner ignoriert den Wert des Jobs. */
    case Never = 'never';

    public static function default(): self
    {
        return self::Off;
    }
}
