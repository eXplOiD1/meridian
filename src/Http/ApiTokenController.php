<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\ApiTokenRecord;
use Meridian\Auth\ApiTokenService;
use Meridian\Auth\AuditLog;
use Meridian\Auth\AuthService;
use Meridian\Auth\CsrfGuard;
use Meridian\Config;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zugriffstoken verwalten (ADR 0009), nur mit der Sitzung des Benutzers (nie mit einem Token, der Pfad liegt außerhalb der
 * Token-Bereiche):
 *
 *   GET    /api/auth/tokens          eigene Token, ohne Hash und ohne Klartext
 *   POST   /api/auth/tokens          {name, read_only, expires_in_days, current_password} → Klartext genau einmal in der Antwort
 *   DELETE /api/auth/tokens/{id}     widerruft ein eigenes Token (fremdes oder unbekanntes: 404)
 *
 * Anlegen verlangt das aktuelle Passwort (gleiche Sperre wie die Anmeldung): ein gestohlener Sitzungs-Cookie allein erzeugt
 * kein dauerhaftes Zugriffsrecht. Audit `token.created`/`token.revoked` mit Namen und Umfang, nie dem Token.
 */
final class ApiTokenController
{
    private const MAX_BODY = 4096;
    private const FIELDS = ['name', 'read_only', 'expires_in_days', 'current_password'];

    public function __construct(
        private readonly Config $config,
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AuthService $auth,
        private readonly ApiTokenService $tokens,
        private readonly AuditLog $audit,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('auth_tokens_list', '/api/auth/tokens', $this->list(...));
        $kernel->post('auth_tokens_create', '/api/auth/tokens', $this->create(...));
        $kernel->delete('auth_tokens_revoke', '/api/auth/tokens/{id}', $this->revoke(...), ['id' => '[1-9][0-9]{0,17}']);
    }

    public function list(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }

        return JsonReply::json(['tokens' => array_map(self::present(...), $this->tokens->list($session->userId)), 'max_tokens' => ApiTokenService::MAX_PER_USER]);
    }

    public function create(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if (!$this->csrf->check($request, $session->token)) {
            return JsonReply::csrfFailed();
        }
        $user = $this->users->findById($session->userId);
        if ($user === null || !$user->isActive) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        $ip = ClientIp::forThrottle($request, $this->config);
        if ($ip instanceof Response) {
            return $ip;
        }

        $data = JsonBody::object($request->getContent(), self::MAX_BODY, 2);
        $shape = 'JSON-Objekt mit name, read_only, expires_in_days und current_password erwartet (höchstens 4 KiB).';
        if ($data === null) {
            throw ValidationFailed::field('body', $shape);
        }
        if ($data !== [] && array_is_list($data)) {
            throw ValidationFailed::field('body', $shape);
        }
        foreach (array_keys($data) as $key) {
            if (!in_array((string) $key, self::FIELDS, true)) {
                throw ValidationFailed::field((string) $key, 'Unbekanntes Feld. Erlaubt sind: ' . implode(', ', self::FIELDS) . '.');
            }
        }
        $name = $data['name'] ?? null;
        $readOnly = $data['read_only'] ?? true;
        $days = array_key_exists('expires_in_days', $data) ? $data['expires_in_days'] : ApiTokenService::DEFAULT_EXPIRY_DAYS;
        $password = $data['current_password'] ?? null;
        $errors = [];
        if (!is_string($name)) {
            $errors['name'] = 'Name: 1 bis 60 Zeichen ohne Steuerzeichen.';
        }
        if (!is_bool($readOnly)) {
            $errors['read_only'] = 'Muss true (nur lesen) oder false (lesen und schreiben) sein.';
        }
        if ($days !== null && !is_int($days)) {
            $errors['expires_in_days'] = 'Gültigkeit: ganze Zahl von 1 bis ' . ApiTokenService::MAX_EXPIRY_DAYS . ' oder null (läuft nie ab).';
        }
        if (!is_string($password) || $password === '' || strlen($password) > AuthService::MAX_PASSWORD) {
            $errors['current_password'] = 'Zur Bestätigung das aktuelle Passwort angeben.';
        }
        if ($errors !== [] || !is_string($name) || !is_bool($readOnly) || !is_string($password) || ($days !== null && !is_int($days))) {
            throw new ValidationFailed($errors);
        }
        // Erst Eingabe und Grenzen prüfen, dann das Passwort (Fehlversuche zählen in die Sperre der Anmeldung).
        if (!$this->auth->confirmPassword($user, $password, $ip)) {
            return JsonReply::error(403, 'Das aktuelle Passwort ist falsch.');
        }
        $created = $this->tokens->create($session->userId, $name, $days, $readOnly, $this->audit);

        return JsonReply::json(['token' => self::present($created['record']), 'plain' => $created['plain'], 'note' => 'Das Token wird nur jetzt angezeigt. Bewahre es sicher auf; es lässt sich nicht wieder anzeigen.'], 201);
    }

    public function revoke(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if (!$this->csrf->check($request, $session->token)) {
            return JsonReply::csrfFailed();
        }
        if (!$this->tokens->revoke($session->userId, (int) $request->attributes->getString('id'), $this->audit)) {
            return JsonReply::error(404, 'Token nicht gefunden.');
        }

        return new Response(null, 204, ['Cache-Control' => 'no-store']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(ApiTokenRecord $token): array
    {
        return ['id' => $token->id, 'name' => $token->name, 'read_only' => $token->readOnly, 'expires_at' => $token->expiresAt, 'last_used_at' => $token->lastUsedAt, 'created_at' => $token->createdAt];
    }
}
