<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\PasswordChangeRequired;
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

    /**
     * Die Sitzung für jeden Endpunkt außer `me`, `password` und `logout`. Bei offenem Pflicht-Passwortwechsel wirft sie
     * (fail-closed: ein neuer Endpunkt ist ohne eigenes Zutun gesperrt); der Kernel antwortet 403.
     *
     * @throws PasswordChangeRequired
     */
    public function authenticate(#[\SensitiveParameter] Request $request): ?Session
    {
        $session = $this->authenticateAllowingPasswordChange($request);
        if ($session !== null && $session->passwordChangeRequired) {
            throw new PasswordChangeRequired();
        }

        return $session;
    }

    /**
     * Nur für `GET /api/auth/me`, `POST /api/auth/password` und `POST /api/auth/logout` (ADR 0005, E6): liefert die
     * Sitzung auch bei offenem Pflichtwechsel. Rechte gibt es dann trotzdem keine (`grantsFor()` ist leer).
     */
    public function authenticateAllowingPasswordChange(#[\SensitiveParameter] Request $request): ?Session
    {
        $token = $request->cookies->get($this->cookieName($request));

        return is_string($token) ? $this->sessions->resolve($token) : null;
    }
}
