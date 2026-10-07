<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Ein Hop: Methode, geprüftes Ziel mit festgehaltenen Adressen, Header und Body (geheim), Zeitbudget.
 *
 * Header und Body erscheinen in keiner Debug-Ausgabe; die Klasse ist nicht serialisierbar.
 */
final readonly class TransportRequest
{
    /**
     * @param 'GET'|'POST'|'PUT'|'PATCH'|'DELETE'|'HEAD' $method
     * @param list<array{0: string, 1: string}>         $headers
     * @param int                                       $timeoutMs verbleibende Gesamtzeit des Laufs für diesen Hop
     */
    public function __construct(
        public string $method,
        public PinnedTarget $target,
        #[\SensitiveParameter] public array $headers,
        #[\SensitiveParameter] public ?string $body,
        public int $timeoutMs,
    ) {
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'origin' => $this->target->url->origin(),
            'header_count' => count($this->headers),
            'has_body' => $this->body !== null,
            'timeout_ms' => $this->timeoutMs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Eine Anfrage mit Geheimnissen wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Eine Anfrage mit Geheimnissen wird nicht deserialisiert.');
    }
}
