<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\AuthService;
use Meridian\Auth\Clock;
use Meridian\Auth\CsrfGuard;
use Meridian\Auth\LoginStatus;
use Meridian\Auth\Session;
use Meridian\Auth\SessionManager;
use Meridian\Auth\TooManyAttempts;
use Meridian\Auth\TwoFactor;
use Meridian\Config;
use Meridian\Security\Permission;
use Meridian\User\UserAccount;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anmelden, Abmelden und „wer bin ich“. Die Sitzung liegt in einem HttpOnly-Cookie,
 * ändernde Anfragen brauchen zusätzlich das CSRF-Token aus /api/auth/me bzw. der Anmeldung.
 */
final class AuthController
{
    private const MAX_BODY = 4096;
    private const MAX_CODE = 32;

    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly Clock $clock,
        private readonly TwoFactor $twoFactor,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->post('auth_login', '/api/auth/login', $this->login(...));
        $kernel->post('auth_logout', '/api/auth/logout', $this->logout(...));
        $kernel->get('auth_me', '/api/auth/me', $this->me(...));
        $kernel->post('auth_2fa_setup', '/api/auth/2fa/setup', $this->twoFactorSetup(...));
        $kernel->post('auth_2fa_enable', '/api/auth/2fa/enable', $this->twoFactorEnable(...));
        $kernel->post('auth_2fa_disable', '/api/auth/2fa/disable', $this->twoFactorDisable(...));
    }

    public function login(Request $request): Response
    {
        // Ohne Sitzung gibt es kein Token; die Herkunftsprüfung schützt vor fremden Anmeldeformularen.
        if (!$this->csrf->originMatches($request)) {
            return self::error(403, 'Ungültige Herkunft der Anfrage.');
        }

        $credentials = $this->credentials($request);
        if ($credentials === null) {
            return self::error(400, 'Ungültige Anfrage: JSON mit username und password erwartet.');
        }

        if ($request->headers->has('X-Forwarded-For') && $this->config->trustedProxies === []) {
            // Sonst zählt die Sperre alle Clients als eine IP (die des Proxys) und sperrt im Zweifel alle gemeinsam.
            error_log('Meridian: Die Anfrage kommt über einen Proxy (X-Forwarded-For), aber MERIDIAN_TRUSTED_PROXIES ist nicht gesetzt. Die Sperre nach Fehlversuchen sieht nur die IP des Proxys.');
        }

        $result = $this->auth->login($credentials['username'], $credentials['password'], $request->getClientIp() ?? 'unbekannt', $credentials['totp_code']);
        if ($result->status === LoginStatus::Locked) {
            $response = self::error(429, 'Zu viele Fehlversuche. Bitte später erneut versuchen.');
            $response->headers->set('Retry-After', (string) $result->retryAfter);

            return $response;
        }
        if ($result->status === LoginStatus::TotpRequired) {
            // Erst nach richtigem Passwort: die Oberfläche fragt dann den Code ab.
            return self::json(['error' => 'Zweiter Faktor erforderlich.', 'totp_required' => true], 401);
        }
        if ($result->status !== LoginStatus::Success || $result->session === null) {
            return self::error(401, AuthService::MESSAGE_INVALID);
        }

        $response = $this->profile($result->session) ?? self::error(401, AuthService::MESSAGE_INVALID);
        $response->headers->setCookie($this->cookie($result->session->token));

        return $response;
    }

    public function logout(Request $request): Response
    {
        $session = $this->authenticate($request);
        if ($session === null) {
            return self::error(401, 'Nicht angemeldet.');
        }
        if (!$this->csrf->check($request, $session->token)) {
            return self::error(403, 'CSRF-Prüfung fehlgeschlagen.');
        }

        $this->auth->logout($session);
        $response = self::json(['status' => 'ok']);
        $response->headers->setCookie($this->expiredCookie());

        return $response;
    }

    public function me(Request $request): Response
    {
        $session = $this->authenticate($request);
        if ($session === null) {
            return self::error(401, 'Nicht angemeldet.');
        }

        return $this->profile($session) ?? self::error(401, 'Nicht angemeldet.');
    }

    /**
     * Einrichtung: liefert das Secret genau einmal im Klartext, damit es in die App übernommen werden kann.
     * Betrifft nur das eigene Konto, deshalb kein Recht aus AccessControl, aber Sitzung und CSRF.
     */
    public function twoFactorSetup(Request $request): Response
    {
        $user = $this->ownAccount($request);
        if ($user instanceof Response) {
            return $user;
        }

        $setup = $this->auth->startTwoFactorSetup($user);
        if ($setup === null) {
            return self::error(409, 'Die Zwei-Faktor-Anmeldung ist bereits aktiv. Zum Neueinrichten zuerst deaktivieren.');
        }

        return self::json(['secret' => $setup['secret'], 'otpauth_uri' => $setup['uri']]);
    }

    public function twoFactorEnable(Request $request): Response
    {
        $user = $this->ownAccount($request);
        if ($user instanceof Response) {
            return $user;
        }

        $ip = $request->getClientIp() ?? 'unbekannt';
        $data = $this->body($request);
        if ($data === null || !isset($data['code'], $data['password']) || !is_string($data['code']) || !is_string($data['password']) || strlen($data['code']) > self::MAX_CODE) {
            return self::error(400, 'Ungültige Anfrage: JSON mit password und code erwartet.');
        }

        try {
            $recovery = $this->auth->enableTwoFactor($user, $data['password'], $data['code'], $ip);
        } catch (TooManyAttempts $e) {
            return self::tooMany($e);
        }
        if ($recovery === null) {
            return self::error(400, 'Passwort oder Code falsch. Erst /api/auth/2fa/setup aufrufen und den aktuellen Code der App eingeben.');
        }

        return self::json(['status' => 'ok', 'recovery_codes' => $recovery]);
    }

    public function twoFactorDisable(Request $request): Response
    {
        $user = $this->ownAccount($request);
        if ($user instanceof Response) {
            return $user;
        }

        $ip = $request->getClientIp() ?? 'unbekannt';
        $data = $this->body($request);
        if ($data === null || !isset($data['password'], $data['code']) || !is_string($data['password']) || !is_string($data['code']) || strlen($data['code']) > self::MAX_CODE) {
            return self::error(400, 'Ungültige Anfrage: JSON mit password und code erwartet.');
        }

        try {
            $disabled = $this->auth->disableTwoFactor($user, $data['password'], $data['code'], $ip);
        } catch (TooManyAttempts $e) {
            return self::tooMany($e);
        }
        if (!$disabled) {
            return self::error(400, 'Passwort oder Code falsch.');
        }

        return self::json(['status' => 'ok']);
    }

    /**
     * Sitzung, CSRF und aktives Konto für Aktionen am eigenen Konto.
     */
    private function ownAccount(Request $request): UserAccount|Response
    {
        $session = $this->authenticate($request);
        if ($session === null) {
            return self::error(401, 'Nicht angemeldet.');
        }
        if (!$this->csrf->check($request, $session->token)) {
            return self::error(403, 'CSRF-Prüfung fehlgeschlagen.');
        }

        $user = $this->users->findById($session->userId);

        return $user !== null && $user->isActive ? $user : self::error(401, 'Nicht angemeldet.');
    }

    private static function tooMany(TooManyAttempts $e): Response
    {
        $response = self::error(429, $e->getMessage());
        $response->headers->set('Retry-After', (string) $e->retryAfter);

        return $response;
    }

    private function authenticate(Request $request): ?Session
    {
        return $this->sessionAuth->authenticate($request);
    }

    private function profile(Session $session): ?JsonResponse
    {
        $user = $this->users->findById($session->userId);
        if ($user === null || !$user->isActive) {
            return null;
        }

        // Rechte bei jeder Anfrage frisch laden, nie aus der Sitzung.
        $roles = [];
        foreach ($this->users->grantsFor($user->id) as $grant) {
            $roles[] = [
                'role' => $grant->role,
                'permissions' => array_map(static fn (Permission $p): string => $p->value, $grant->permissions),
                'categories' => $grant->categories,
            ];
        }

        return self::json([
            'user' => ['id' => $user->id, 'username' => $user->username, 'display_name' => $user->displayName],
            'roles' => $roles,
            'totp_enabled' => $this->twoFactor->isEnabled($user->id),
            'csrf_token' => $this->csrf->tokenFor($session->token),
        ]);
    }

    /**
     * @return array{username: string, password: string, totp_code: string|null}|null
     */
    private function credentials(Request $request): ?array
    {
        $data = $this->body($request);
        if ($data === null || !isset($data['username'], $data['password']) || !is_string($data['username']) || !is_string($data['password'])) {
            return null;
        }

        $code = null;
        if (isset($data['totp_code'])) {
            if (!is_string($data['totp_code']) || strlen($data['totp_code']) > self::MAX_CODE) {
                return null;
            }
            $code = $data['totp_code'];
        }

        return ['username' => $data['username'], 'password' => $data['password'], 'totp_code' => $code];
    }

    /**
     * @return array<mixed>|null
     */
    private function body(Request $request): ?array
    {
        return JsonBody::object($request->getContent(), self::MAX_BODY);
    }

    private function cookieName(): string
    {
        return $this->sessionAuth->cookieName();
    }

    private function cookie(#[\SensitiveParameter] string $token): Cookie
    {
        $expires = $this->clock->now()->modify('+' . SessionManager::ABSOLUTE_SECONDS . ' seconds');

        return Cookie::create($this->cookieName(), $token, $expires, '/', null, !$this->config->isDev(), true, false, Cookie::SAMESITE_STRICT);
    }

    private function expiredCookie(): Cookie
    {
        return Cookie::create($this->cookieName(), '', 1, '/', null, !$this->config->isDev(), true, false, Cookie::SAMESITE_STRICT);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private static function error(int $status, string $message): JsonResponse
    {
        return self::json(['error' => $message], $status);
    }
}
