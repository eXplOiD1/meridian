<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Skript oder Umgebung ungültig oder gespeichertes Skript nicht lesbar. `field` nennt den Pfad im Anfragekörper der
 * API (`script.env[2].value`), die Meldung sagt, was falsch ist. Beide enthalten nie einen Wert.
 */
final class InvalidShellPayload extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function unreadable(): self
    {
        return new self('payload', 'Gespeichertes Skript nicht lesbar (Schlüssel oder Format). Skript im Job neu eingeben.');
    }

    public static function unknownVersion(): self
    {
        return new self('payload', 'Gespeichertes Skript hat ein unbekanntes Format. Skript im Job neu eingeben.');
    }
}
