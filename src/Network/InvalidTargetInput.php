<?php

declare(strict_types=1);

namespace Meridian\Network;

/**
 * Eingabe für eine Freigabe ungültig (Feld und feste Meldung, nie der Wert). API → 422, CLI → Fehlermeldung.
 */
final class InvalidTargetInput extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
