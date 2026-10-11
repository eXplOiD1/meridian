<?php

declare(strict_types=1);

namespace Meridian\Tests\Support;

use Meridian\Runner\Heartbeat;
use Meridian\Runner\Http\HttpTransport;
use Meridian\Runner\Http\TransportRequest;
use Meridian\Runner\Http\TransportResponse;

/**
 * Transport für Tests ohne Netz: liefert vorgegebene Antworten der Reihe nach (die letzte bleibt stehen) und
 * merkt sich jeden Hop.
 */
final class FakeTransport implements HttpTransport
{
    /** @var list<TransportRequest> */
    public array $requests = [];

    /** @var (\Closure(TransportRequest, Heartbeat): void)|null */
    public ?\Closure $during = null;

    /** @var list<TransportResponse> */
    private array $responses;

    public function __construct(TransportResponse ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public static function response(int $status, string $body = '', ?string $location = null, ?string $contentType = 'text/plain', int $durationMs = 120): TransportResponse
    {
        return new TransportResponse($status, null, null, $location, $contentType, $body, strlen($body), false, $durationMs);
    }

    #[\Override]
    public function send(TransportRequest $request, Heartbeat $heartbeat): TransportResponse
    {
        $this->requests[] = $request;
        if ($this->during !== null) {
            ($this->during)($request, $heartbeat);
        }

        return count($this->responses) > 1 ? array_shift($this->responses) : ($this->responses[0] ?? self::response(200));
    }
}
