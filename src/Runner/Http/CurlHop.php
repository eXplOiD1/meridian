<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Zustand eines laufenden Hops in {@see CurlTransport}: was die curl-Rückrufe gesehen haben.
 *
 * @internal nur CurlTransport
 */
final class CurlHop
{
    public int $status = 0;

    public ?string $location = null;

    public ?string $contentType = null;

    public int $headerBytes = 0;

    public string $body = '';

    public int $bodyBytes = 0;

    public bool $bodyLimitReached = false;

    /** Grund, aus dem ein Rückruf die Übertragung abgebrochen hat. */
    public ?TransportError $abort = null;

    /** Ist die verbundene Adresse schon gegen die festgehaltenen geprüft? */
    public bool $peerChecked = false;

    /**
     * @param non-empty-list<string> $pinnedIps kanonische Textform
     */
    public function __construct(
        public readonly array $pinnedIps,
        public readonly int $port,
    ) {
    }

    /**
     * Zweite Absicherung neben CURLOPT_RESOLVE: Liegt die verbundene Adresse unter den festgehaltenen?
     */
    public function allowsPeer(string $ip, int $port): bool
    {
        $packed = IpNetwork::pack($ip);
        $canonical = $packed === null ? false : inet_ntop($packed);

        return $canonical !== false && $port === $this->port && in_array($canonical, $this->pinnedIps, true);
    }

    /**
     * @return array<string, int|bool>
     */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'body_bytes' => $this->bodyBytes];
    }
}
