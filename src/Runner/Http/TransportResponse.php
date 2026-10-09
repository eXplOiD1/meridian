<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Security\Sealed;

/**
 * Ergebnis eines Hops. `body` ist nur der Anfang der Antwort (höchstens {@see CurlTransport::KEEP_BODY_BYTES});
 * `bodyBytes` zählt alles Empfangene. `location` kann Geheimnisse des Ziels tragen und wird nie ausgegeben, nur
 * für die nächste Weiterleitung geprüft. Beide liegen versiegelt ({@see Sealed}) und erscheinen in keiner
 * Darstellung (var_dump, print_r, var_export, json_encode); die Klasse ist nicht serialisierbar.
 */
final readonly class TransportResponse implements \JsonSerializable
{
    /** @var Sealed<array{0: string|null, 1: string}> Location, Body */
    private Sealed $secret;

    /**
     * @param int $status HTTP-Status, 0 wenn keine Antwort kam
     */
    public function __construct(
        public int $status,
        public ?TransportError $error,
        public ?int $errorCode,
        #[\SensitiveParameter] ?string $location,
        public ?string $contentType,
        #[\SensitiveParameter] string $body,
        public int $bodyBytes,
        public bool $bodyLimitReached,
        public int $durationMs,
    ) {
        $this->secret = new Sealed([$location, $body]);
    }

    public function location(): ?string
    {
        return $this->secret->open()[0];
    }

    /** Anfang der Antwort (roh, ungeprüft): vor jeder Ausgabe maskieren, dann kürzen. */
    public function body(): string
    {
        return $this->secret->open()[1];
    }

    public static function failure(TransportError $error, int $durationMs = 0, ?int $errorCode = null): self
    {
        return new self(0, $error, $errorCode, null, null, '', 0, false, $durationMs);
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    public function __debugInfo(): array
    {
        return [
            'status' => $this->status,
            'error' => $this->error?->value,
            'body_bytes' => $this->bodyBytes,
            'duration_ms' => $this->durationMs,
        ];
    }

    /**
     * @return array<string, int|string|bool|null>
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
        throw new \LogicException('Eine Antwort mit möglichen Geheimnissen wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Eine Antwort mit möglichen Geheimnissen wird nicht deserialisiert.');
    }
}
