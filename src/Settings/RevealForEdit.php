<?php

declare(strict_types=1);

namespace Meridian\Settings;

/**
 * Einstellung `jobs.reveal_for_edit` (docs/decisions/0003 N1, 0004 N1): ob `GET /api/jobs/{id}/source` die
 * gespeicherte Anfrage bzw. das Skript eines Jobs im Klartext an Bearbeiter liefert. Standard aus.
 */
enum RevealForEdit: string
{
    /** Standard: der Endpunkt antwortet 403, der Editor zeigt nur „ersetzen“. */
    case Off = 'off';
    /** Wer den Job bearbeiten darf, sieht die gespeicherten Werte im Editor; jeder Abruf wird protokolliert. */
    case On = 'on';

    public static function default(): self
    {
        return self::Off;
    }
}
