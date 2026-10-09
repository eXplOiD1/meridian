<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Settings\ResponseStorage;

/**
 * Die globalen Einstellungen, die ein HTTP-Lauf braucht (docs/decisions/0003, E9/E11). Bei jedem Lauf frisch
 * gelesen; nur lesen, nie schreiben. Ein ungültiger gespeicherter Wert ergibt den Standard, nie etwas Lockereres
 * (siehe {@see \Meridian\Settings\Settings}).
 */
interface HttpRunSettings
{
    /** Globales Maximum des Zeitlimits, 1 bis 3600 s. */
    public function maxTimeoutSeconds(): int;

    public function responseStorage(): ResponseStorage;

    /** `http.display_host = hidden`: die Laufausgabe zeigt statt des Ursprungs nur `https://••••`. */
    public function hidesHost(): bool;
}
