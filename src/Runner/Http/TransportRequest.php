<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Security\Sealed;

/**
 * Ein Hop: Methode, geprüftes Ziel mit festgehaltenen Adressen, Header und Body (geheim), Zeitbudget.
 *
 * Header und Body liegen versiegelt ({@see Sealed}) und erscheinen in keiner Darstellung (var_dump, print_r,
 * var_export, json_encode); die Klasse ist nicht serialisierbar.
 */
final readonly class TransportRequest implements \JsonSerializable
{
    /** @var Sealed<list<array{0: string, 1: string}>> */
    private Sealed $headers;

    /** @var Sealed<string|null> */
    private Sealed $body;

    private int $headerCount;

    private bool $hasBody;

    /**
     * @param 'GET'|'POST'|'PUT'|'PATCH'|'DELETE'|'HEAD' $method
     * @param list<array{0: string, 1: string}>         $headers
     * @param int                                       $timeoutMs verbleibende Gesamtzeit des Laufs für diesen Hop
     */
    public function __construct(
        public string $method,
        public PinnedTarget $target,
        #[\SensitiveParameter] array $headers,
        #[\SensitiveParameter] ?string $body,
        public int $timeoutMs,
    ) {
        $this->headers = new Sealed($headers);
        $this->body = new Sealed($body);
        $this->headerCount = count($headers);
        $this->hasBody = $body !== null;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function headers(): array
    {
        return $this->headers->open();
    }

    public function body(): ?string
    {
        return $this->body->open();
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'origin' => $this->target->url->origin(),
            'header_count' => $this->headerCount,
            'has_body' => $this->hasBody,
            'timeout_ms' => $this->timeoutMs,
        ];
    }

    /**
     * @return array<string, int|string|bool>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
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
