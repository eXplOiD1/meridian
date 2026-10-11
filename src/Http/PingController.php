<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\Clock;
use Meridian\Database\Timestamp;
use Meridian\Monitor\MonitorRepository;
use Meridian\Security\PingToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET|POST /ping/{token}` — Lebenszeichen für eine Überwachung (ADR 0011). Ohne Sitzung: das Token (256 Bit Zufall,
 * nur als Hash gespeichert) ist die Berechtigung. Es gibt kein Cookie, keinen CSRF-Schutz (nichts, was ein Browser
 * automatisch mitschickt) und keinen Anfragekörper. Unbekanntes, erneuertes oder deaktiviertes Token antwortet gleich mit
 * 404 und verrät nichts. Geschrieben wird höchstens alle {@see self::MIN_SECONDS} Sekunden je Überwachung (200 bleibt).
 */
final class PingController
{
    public const MIN_SECONDS = 5;

    public function __construct(private readonly MonitorRepository $monitors, private readonly Clock $clock)
    {
    }

    public function register(Kernel $kernel): void
    {
        $token = ['token' => PingToken::PATTERN];
        $kernel->get('ping_get', '/ping/{token}', $this->ping(...), $token);
        $kernel->post('ping_post', '/ping/{token}', $this->ping(...), $token);
    }

    public function ping(#[\SensitiveParameter] Request $request): Response
    {
        $hash = PingToken::hash($request->attributes->getString('token'));
        $found = $this->monitors->byTokenHash($hash);
        if ($found === null || !$found['enabled']) {
            return JsonReply::error(404, 'Nicht gefunden.');
        }
        $now = $this->clock->now();
        $last = $found['last_ping_at'];
        $recent = false;
        if ($last !== null && $found['state'] === 'up') {
            try {
                $recent = $now->getTimestamp() - Timestamp::parse($last)->getTimestamp() < self::MIN_SECONDS;
            } catch (\InvalidArgumentException) {
                $recent = false;
            }
        }
        if (!$recent) {
            $this->monitors->recordPing($found['id'], Timestamp::format($now));
        }
        $response = new Response("ok\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);

        return $response;
    }
}
