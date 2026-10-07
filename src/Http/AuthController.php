<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\AuthService;
use Meridian\Auth\Clock;
use Meridian\Auth\CsrfGuard;
use Meridian\Auth\LoginStatus;
use Meridian\Auth\Session;
use Meridian\Auth\SessionManager;
use Meridian\Config;
use Meridian\Security\Permission;
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

    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly SessionManager $sessions,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly Clock $clock,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->post('auth_login', '/api/auth/login', $this->login(...));
        $kernel->post('auth_logout', '/api/auth/logout', $this->logout(...));
        $kernel->get('auth_me', '/api/auth/me', $this->me(...));
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

        $result = $this->auth->login($credentials['username'], $credentials['password'], $request->getClientIp() ?? 'unbekannt');
        if ($result->status === LoginStatus::Locked) {
            $response = self::error(429, 'Zu viele Fehlversuche. Bitte später erneut versuchen.');
            $response->headers->set('Retry-After', (string) $result->retryAfter);

            return $response;
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

    private function authenticate(Request $request): ?Session
    {
        $token = $request->cookies->get($this->cookieName());

        return is_string($token) ? $this->sessions->resolve($token) : null;
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
            'csrf_token' => $this->csrf->tokenFor($session->token),
        ]);
    }

    /**
     * @return array{username: string, password: string}|null
     */
    private function credentials(Request $request): ?array
    {
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY) {
            return null;
        }

        try {
            $data = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || !isset($data['username'], $data['password']) || !is_string($data['username']) || !is_string($data['password'])) {
            return null;
        }

        return ['username' => $data['username'], 'password' => $data['password']];
    }

    private function cookieName(): string
    {
        // Das Präfix __Host- erzwingt Secure, Path=/ und kein Domain-Attribut. In dev (HTTP) nicht möglich.
        return $this->config->isDev() ? 'meridian_session' : '__Host-meridian_session';
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
