<?php

declare(strict_types=1);

namespace Meridian\Shell;

/**
 * Eingabe für einen Ausführungsort ungültig (Feld und feste Meldung, nie der Wert). API → 422, CLI → Fehlermeldung.
 */
final class InvalidShellTarget extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
