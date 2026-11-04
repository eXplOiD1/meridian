<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Config;
use Meridian\Security\SecretMasker;
use Symfony\Component\HttpFoundation\IpUtils;
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

    /** Alle Header, an denen ein Reverse-Proxy erkennbar ist, auch die, denen Symfony nur von vertrauten Proxys glaubt. */
    private const FORWARDED_HEADERS = ['X-Forwarded-For', 'X-Forwarded-Proto', 'X-Forwarded-Host', 'Forwarded'];

    /** Antwort bei abgelehnter Herkunft hinter einem Proxy: nennt den Fix, nie die Adresse des Absenders (die steht im Log). */
    public const ORIGIN_MESSAGE = 'Ungültige Herkunft der Anfrage: Meridian läuft vermutlich hinter einem Reverse-Proxy, dessen Adresse nicht in MERIDIAN_TRUSTED_PROXIES steht, oder der Proxy sendet X-Forwarded-Proto nicht. Behebung: MERIDIAN_TRUSTED_PROXIES=auto setzen (Docker-Netz des Containers) oder die Adresse bzw. das Netz des Proxys eintragen, den Proxy X-Forwarded-Proto senden lassen und Meridian neu starten. Die Adresse des Absenders steht im Log von meridian-web.';

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

    /**
     * Ursache einer abgelehnten Herkunft, wenn ein Proxy dahinterstecken dürfte: die fertige Antwort (503 mit
     * `trusted_proxies_required`, die Oberfläche zeigt dafür ihr Proxy-Feld), sonst null. Die Adresse des Absenders und
     * der Eintrag zum Beheben stehen nur im Log, nie in der Antwort.
     *
     * Zwei Fälle: Die Anfrage trägt `X-Forwarded-*`, kommt aber von einer Adresse, die nicht in
     * `MERIDIAN_TRUSTED_PROXIES` steht (Symfony ignoriert die Header dann still). Oder `Origin`/`Referer` nennt ein
     * anderes Schema als die Anfrage bei gleichem Host (TLS am Proxy, aber kein vertrauter `X-Forwarded-Proto`).
     */
    public static function originProblem(#[\SensitiveParameter] Request $request, Config $config): ?JsonResponse
    {
        $peer = $request->server->get('REMOTE_ADDR');
        $peer = is_string($peer) ? $peer : '';
        $trusted = $peer !== '' && $config->trustedProxies !== [] && IpUtils::checkIp($peer, $config->trustedProxies);
        $forwarded = false;
        foreach (self::FORWARDED_HEADERS as $name) {
            if ($request->headers->has($name)) {
                $forwarded = true;

                break;
            }
        }

        $log = null;
        if ($forwarded && !$trusted) {
            $log = 'Meridian: Anmeldung abgelehnt: Die Anfrage von ' . $peer . ' trägt X-Forwarded-*, aber diese Adresse steht nicht in MERIDIAN_TRUSTED_PROXIES. Eintragen: MERIDIAN_TRUSTED_PROXIES=' . $peer . ' (oder auto, wenn der Proxy im Docker-Netz von Meridian liegt) und Meridian neu starten.';
        } elseif (!$forwarded && self::originSchemeDiffers($request)) {
            $log = 'Meridian: Anmeldung abgelehnt: Origin/Referer hat ein anderes Schema als die Anfrage von ' . $peer . ' (vermutlich TLS am Reverse-Proxy). Der Proxy muss X-Forwarded-Proto senden und ' . $peer . ' in MERIDIAN_TRUSTED_PROXIES stehen (MERIDIAN_TRUSTED_PROXIES=' . $peer . ' oder auto).';
        }
        if ($log === null) {
            return null;
        }
        error_log((new SecretMasker())->mask($log));

        return JsonReply::json(['error' => self::ORIGIN_MESSAGE, 'trusted_proxies_required' => true], 503);
    }

    private static function originSchemeDiffers(#[\SensitiveParameter] Request $request): bool
    {
        $source = $request->headers->get('Origin');
        if ($source === null || $source === '' || $source === 'null') {
            $source = $request->headers->get('Referer');
        }
        if ($source === null || $source === '') {
            return false;
        }
        $parts = parse_url($source);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return strtolower($parts['host']) === strtolower($request->getHost()) && strtolower($parts['scheme']) !== $request->getScheme();
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
