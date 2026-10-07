<?php

declare(strict_types=1);

namespace Meridian\Job;

/**
 * Wunsch eines Jobs, die Antwort im Verlauf zu speichern (docs/decisions/0003, E11). Entschieden wird bei jedem
 * Lauf im Runner zusammen mit der globalen Einstellung `http.response_storage`.
 */
enum StoreResponse: string
{
    case Inherit = 'inherit';
    case On = 'on';
    case Off = 'off';
}
