<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Config;
use Meridian\Security\SecretMasker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Client-IP für die Sperre nach Fehlversuchen (mer-security §10, Entscheidung Alex 09.10.2026, Variante A).
 *
 * - `MERIDIAN_TRUSTED_PROXIES` gesetzt: Symfony glaubt `X-Forwarded-For` nur, wenn die Verbindung von einem dieser
 *   Proxys kommt ({@see self::trust()}); von jedem anderen Absender zählt die Adresse der Verbindung. Ein gefälschter
 *   Header ändert die gezählte IP also nie.
 * - Nicht gesetzt, aber die Anfrage trägt `X-Forwarded-For` oder `Forwarded`: Meridian läuft dann (vermutlich) hinter
 *   einem Proxy und sähe nur dessen IP — alle Clients teilten sich eine Sperre. Solche Anfragen an Endpunkte mit
 *   IP-Sperre werden abgelehnt (503 mit Hinweis), statt die Sperre still unwirksam oder gemeinsam zu machen.
 */
final class ClientIp
{
    /** Header, an denen ein Reverse-Proxy erkennbar ist. */
    public const PROXY_HEADERS = ['X-Forwarded-For', 'Forwarded'];

    public const MESSAGE = 'Die Anfrage kommt über einen Reverse-Proxy (Header X-Forwarded-For oder Forwarded), aber MERIDIAN_TRUSTED_PROXIES ist nicht gesetzt. Ohne diese Angabe kann Meridian Fehlversuche nicht je Client zählen, deshalb ist die Anmeldung gesperrt. Behebung: MERIDIAN_TRUSTED_PROXIES auf die IP-Adresse oder das Netz des Proxys setzen (z. B. 172.19.0.0/16) und Meridian neu starten.';

    private const LOG = 'Meridian: Anmeldung abgelehnt: Die Anfrage trägt X-Forwarded-For/Forwarded, aber MERIDIAN_TRUSTED_PROXIES ist nicht gesetzt. MERIDIAN_TRUSTED_PROXIES auf die IP oder das Netz des Reverse-Proxys setzen.';

    /**
     * Glaubt `X-Forwarded-*` nur von den konfigurierten Proxys (einmal beim Start, `public/index.php`).
     */
    public static function trust(Config $config): void
    {
        // Leere Liste = niemandem glauben (auch das setzt einen früheren Stand zurück).
        Request::setTrustedProxies(
            $config->trustedProxies,
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_HOST,
        );
    }

    /**
     * IP für die Sperre oder die fertige Ablehnung, wenn ein Proxy-Header ohne `MERIDIAN_TRUSTED_PROXIES` ankommt.
     */
    public static function forThrottle(#[\SensitiveParameter] Request $request, Config $config): string|JsonResponse
    {
        if ($config->trustedProxies === [] && self::hasProxyHeader($request)) {
            error_log((new SecretMasker())->mask(self::LOG));

            return JsonReply::json(['error' => self::MESSAGE, 'trusted_proxies_required' => true], 503);
        }

        return $request->getClientIp() ?? 'unbekannt';
    }

    public static function hasProxyHeader(#[\SensitiveParameter] Request $request): bool
    {
        foreach (self::PROXY_HEADERS as $name) {
            if ($request->headers->has($name)) {
                return true;
            }
        }

        return false;
    }
}
