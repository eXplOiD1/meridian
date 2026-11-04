<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Auth;

use Symfony\Component\HttpFoundation\Request;

/**
 * CSRF-Schutz für ändernde Anfragen: ein an die Sitzung gebundenes Token im Header
 * X-CSRF-Token und zusätzlich die Herkunft (Origin bzw. Referer) gegen die eigene Adresse (Schema, Host und Port).
 */
final class CsrfGuard
{
    public const HEADER = 'X-CSRF-Token';

    /**
     * Das Token wird aus der Sitzungs-ID abgeleitet, muss also nicht gespeichert werden und ist
     * nur mit gültiger Sitzung gültig.
     */
    public function tokenFor(#[\SensitiveParameter] string $sessionToken): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'meridian-csrf-v1', $sessionToken, true)), '+/', '-_'), '=');
    }

    public function check(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] string $sessionToken): bool
    {
        // Zugriffstoken im Header (setzt nur SessionAuth nach erfolgreicher Prüfung): kein Cookie, also kein CSRF.
        if ($request->attributes->get('meridian.auth') === 'token') {
            return true;
        }
        $given = $request->headers->get(self::HEADER);
        if ($given === null || !hash_equals($this->tokenFor($sessionToken), $given)) {
            return false;
        }

        return $this->originMatches($request);
    }

    /**
     * Nur das Token im Header, gegen die Sitzung geprüft (für lesende Anfragen ohne `Sec-Fetch-Site`).
     */
    public function tokenValid(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] string $sessionToken): bool
    {
        $given = $request->headers->get(self::HEADER);

        return $given !== null && hash_equals($this->tokenFor($sessionToken), $given);
    }

    /**
     * Wie {@see originMatches()}, aber ohne Origin und Referer ist das in Ordnung; sind sie da, müssen Schema, Host und Port passen.
     */
    public function originMatchesIfPresent(#[\SensitiveParameter] Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        $referer = $request->headers->get('Referer');
        if (($origin === null || $origin === '') && ($referer === null || $referer === '')) {
            return true;
        }

        return $this->originMatches($request);
    }

    /**
     * Herkunftsprüfung ohne Sitzung (Anmeldung): fehlt Origin und Referer, wird abgelehnt.
     */
    public function originMatches(#[\SensitiveParameter] Request $request): bool
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
        // Schema, Host und Port müssen zur eigenen Adresse passen (Request wertet Proxy-Header nur von vertrauten Proxys aus).
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== $request->getScheme() || strtolower($parts['host']) !== strtolower($request->getHost())) {
            return false;
        }

        return ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) === (int) $request->getPort();
    }
}
