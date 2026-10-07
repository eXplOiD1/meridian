<?php

declare(strict_types=1);

namespace Meridian\Http;

/**
 * Eingabe ungültig: der Kernel macht daraus eine 422-Antwort mit den Feldfehlern.
 *
 * Die Meldungen sind feste deutsche Texte, die sagen, was falsch ist und wie es richtig geht. Sie
 * enthalten nie den Eingabewert (er könnte ein Geheimnis sein).
 */
final class ValidationFailed extends \RuntimeException
{
    /**
     * @param array<string, string> $fields Feldpfad => feste Meldung
     */
    public function __construct(private readonly array $fields)
    {
        parent::__construct('Die Eingaben sind ungültig.');
    }

    public static function field(string $field, string $message): self
    {
        return new self([$field => $message]);
    }

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
