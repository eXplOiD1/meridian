<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\ApiTokenService;
use Meridian\Auth\PasswordChangeRequired;
use Meridian\Auth\Session;
use Meridian\Auth\SessionManager;
use Meridian\Auth\TokenNotAllowed;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Liest die Sitzung aus dem Cookie. Eine Stelle für alle Endpunkte, damit keiner eine eigene
 * (womöglich laxere) Prüfung baut.
 */
final class SessionAuth
{
    /** Wird nur hier gesetzt (Request-Attribute kommen nie vom Client): die Anfrage kam mit einem gültigen Zugriffstoken. */
    public const AUTH_ATTRIBUTE = 'meridian.auth';
    public const AUTH_TOKEN = 'token';

    /** Bereiche, die ein Zugriffstoken nutzen darf (ADR 0009): Jobs, Läufe, Übersicht, Statistik; Kategorien nur lesend. */
    private const TOKEN_AREAS = '#^/api/(jobs|runs|overview|stats)(/|$)#';

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ?ApiTokenService $tokens = null,
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
        $bearer = self::bearer($request);
        if ($bearer !== null) {
            return $this->authenticateToken($request, $bearer);
        }
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
        if (self::bearer($request) !== null) {
            return null; // Zugriffstoken gelten nie für Konto, Passwort und Abmelden
        }
        $token = $request->cookies->get($this->cookieName($request));

        return is_string($token) ? $this->sessions->resolve($token) : null;
    }

    /**
     * Der Token aus `Authorization: Bearer …` oder null, wenn der Header fehlt. Ist er da, gilt **nur** er: ein gleichzeitiges
     * Sitzungs-Cookie wird ignoriert (kein Vermischen von Cookie-Rechten und Token-Umfang).
     */
    private static function bearer(#[\SensitiveParameter] Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        // Andere Schemes (z. B. Basic von einem vorgeschalteten Proxy) gehen uns nichts an: weiter mit dem Cookie.
        if (!is_string($header) || stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        return preg_match('/^Bearer ([\x21-\x7E]+)$/Di', $header, $match) === 1 ? $match[1] : '';
    }

    /**
     * @throws TokenNotAllowed
     */
    private function authenticateToken(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] string $plain): ?Session
    {
        $grant = $this->tokens?->resolve($plain);
        if ($grant === null) {
            return null;
        }
        $path = $request->getPathInfo();
        $method = $request->getMethod();
        $reads = $method === 'GET' || $method === 'HEAD';
        $categories = $path === '/api/categories';
        if (preg_match(self::TOKEN_AREAS, $path) !== 1 && !($categories && $reads)) {
            throw new TokenNotAllowed(TokenNotAllowed::AREA);
        }
        if (preg_match('#/source$#', $path) === 1) {
            throw new TokenNotAllowed(TokenNotAllowed::SECRETS);
        }
        if ($grant->readOnly && !$reads) {
            throw new TokenNotAllowed(TokenNotAllowed::READ_ONLY);
        }
        $request->attributes->set(self::AUTH_ATTRIBUTE, self::AUTH_TOKEN);

        return new Session($grant->userId, $plain, null, false);
    }

    /** Das Sitzungs-Cookie: HttpOnly, SameSite=Strict, Secure nur über HTTPS (Name mit `__Host-`-Präfix, siehe cookieName()). */
    public function cookie(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] string $token, \DateTimeImmutable $expires): Cookie
    {
        return Cookie::create($this->cookieName($request), $token, $expires, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_STRICT);
    }

    public function expiredCookie(#[\SensitiveParameter] Request $request): Cookie
    {
        return Cookie::create($this->cookieName($request), '', 1, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_STRICT);
    }
}
