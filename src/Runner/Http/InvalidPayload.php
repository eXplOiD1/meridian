<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Anfrage (URL, Header, Body) ungültig oder gespeicherte Anfrage nicht lesbar. `field` nennt den Pfad im
 * Anfragekörper der API (`request.headers[2].value`), die Meldung sagt, was falsch ist. Beide enthalten nie
 * einen Wert.
 */
final class InvalidPayload extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function unreadable(): self
    {
        return new self('payload', 'Gespeicherte Anfrage nicht lesbar (Schlüssel oder Format). Anfrage im Job neu eingeben.');
    }

    public static function unknownVersion(): self
    {
        return new self('payload', 'Gespeicherte Anfrage hat ein unbekanntes Format. Anfrage im Job neu eingeben.');
    }
}
