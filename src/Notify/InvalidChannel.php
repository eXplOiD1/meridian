<?php

declare(strict_types=1);

namespace Meridian\Notify;

/** Ungültige Kanal-Eingabe: Feld und feste Meldung, nie ein Wert. */
final class InvalidChannel extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
