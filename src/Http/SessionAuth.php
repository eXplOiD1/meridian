<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\Session;
use Meridian\Auth\SessionManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * Liest die Sitzung aus dem Cookie. Eine Stelle für alle Endpunkte, damit keiner eine eigene
 * (womöglich laxere) Prüfung baut.
 */
final class SessionAuth
{
    public function __construct(
        private readonly SessionManager $sessions,
    ) {
    }

    /**
     * Über HTTPS: Name mit __Host-Präfix (erzwingt Secure, Path=/, kein Domain-Attribut) und Secure-Flag.
     * Über reines HTTP (z. B. im LAN ohne Proxy) kann ein Browser Secure-Cookies nicht speichern; dort gilt der
     * Name ohne Präfix und ohne Secure. Ob HTTPS vorliegt, entscheidet Request::isSecure() (Proxy-Header nur von
     * vertrauenswürdigen Proxys).
     */
    public function cookieName(#[\SensitiveParameter] Request $request): string
    {
        return $request->isSecure() ? '__Host-meridian_session' : 'meridian_session';
    }

    public function authenticate(#[\SensitiveParameter] Request $request): ?Session
    {
        $token = $request->cookies->get($this->cookieName($request));

        return is_string($token) ? $this->sessions->resolve($token) : null;
    }
}
