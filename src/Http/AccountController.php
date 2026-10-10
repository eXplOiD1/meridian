<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\AuditLog;
use Meridian\Auth\AuthService;
use Meridian\Auth\Clock;
use Meridian\Auth\CsrfGuard;
use Meridian\Auth\Session;
use Meridian\Auth\SessionManager;
use Meridian\Auth\SessionView;
use Meridian\Config;
use Meridian\Security\SecretMasker;
use Meridian\User\DisplayName;
use Meridian\User\UserAccount;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Eigene Daten und Sitzungen (docs/decisions/0005, E10, §4.2). Bezug ist immer die Sitzung (`session.userId`), nie eine
 * Benutzer-ID aus der Anfrage; einzige ID aus der Anfrage ist die Sitzungs-ID, geprüft mit `AND user_id` (fremd → 404).
 * Kein `AccessControl`-Recht: jeder angemeldete, aktive Benutzer darf sein eigenes Konto ändern.
 *
 * `POST /api/auth/password` ist (neben `me` und `logout`) der einzige Endpunkt, der bei offenem Pflicht-Passwortwechsel
 * geht.
 */
final class AccountController
{
    private const MAX_BODY = 4096;

    public function __construct(
        private readonly Config $config,
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly SessionManager $sessions,
        private readonly AuthService $auth,
        private readonly AuditLog $audit,
        private readonly SecretMasker $masker,
        private readonly Clock $clock,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->put('auth_profile', '/api/auth/profile', $this->profile(...));
        $kernel->post('auth_password', '/api/auth/password', $this->password(...));
        $kernel->get('auth_sessions', '/api/auth/sessions', $this->listSessions(...));
        $kernel->post('auth_sessions_end_others', '/api/auth/sessions/end-others', $this->endOthers(...));
        $kernel->delete('auth_sessions_end', '/api/auth/sessions/{id}', $this->endSession(...), ['id' => '[1-9][0-9]{0,17}']);
    }

    public function profile(#[\SensitiveParameter] Request $request): Response
    {
        $own = $this->own($request, mutating: true);
        if ($own instanceof Response) {
            return $own;
        }
        [, $user] = $own;

        $data = self::body($request, ['display_name']);
        $displayName = DisplayName::validate($data['display_name']);
        $this->users->updateDisplayName($user->id, $displayName);
        $this->audit->record($user->id, 'user.renamed', 'user:' . $user->id . ' ' . $user->username . ': Anzeigename geändert');

        return JsonReply::json(['user' => [
            'id' => $user->id,
            'username' => $this->masker->mask($user->username),
            'display_name' => $this->masker->mask($displayName),
        ]]);
    }

    /**
     * Passwort ändern (auch als Pflichtwechsel): alle Sitzungen enden, die aktuelle wird durch eine neue ersetzt (neues
     * Cookie, neues CSRF-Token in der Antwort). Falsches aktuelles Passwort → 403 und zählt für die Sperre (429).
     */
    public function password(#[\SensitiveParameter] Request $request): Response
    {
        $own = $this->own($request, mutating: true, allowPasswordChange: true);
        if ($own instanceof Response) {
            return $own;
        }
        [$session, $user] = $own;

        $ip = ClientIp::forThrottle($request, $this->config);
        if ($ip instanceof Response) {
            return $ip;
        }
        $data = self::body($request, ['current_password', 'new_password']);
        $old = $data['current_password'];
        $new = $data['new_password'];
        $errors = [];
        if (!is_string($old) || strlen($old) > AuthService::MAX_PASSWORD) {
            $errors['current_password'] = 'Das Passwort muss ein Text mit höchstens 1024 Byte sein.';
        }
        if (!is_string($new) || strlen($new) > AuthService::MAX_PASSWORD) {
            $errors['new_password'] = 'Das Passwort muss ein Text mit höchstens 1024 Byte sein.';
        }
        if ($errors !== [] || !is_string($old) || !is_string($new)) {
            throw new ValidationFailed($errors);
        }

        $fresh = $this->auth->changeOwnPassword($user, $session, $old, $new, $ip);
        if ($fresh === null) {
            return JsonReply::error(403, 'Das aktuelle Passwort ist falsch.');
        }

        $response = JsonReply::json([
            'status' => 'ok',
            'password_change_required' => false,
            'csrf_token' => $this->csrf->tokenFor($fresh->token),
        ]);
        $response->headers->setCookie($this->sessionAuth->cookie($request, $fresh->token, $this->clock->now()->modify('+' . SessionManager::ABSOLUTE_SECONDS . ' seconds')));

        return $response;
    }

    public function listSessions(#[\SensitiveParameter] Request $request): Response
    {
        $own = $this->own($request, mutating: false);
        if ($own instanceof Response) {
            return $own;
        }
        [$session, $user] = $own;

        return JsonReply::json(['sessions' => array_map(
            fn (SessionView $view): array => [
                'id' => $view->id,
                'created_at' => $view->createdAt,
                'last_seen_at' => $view->lastSeenAt,
                'expires_at' => $view->expiresAt,
                'current' => $view->current,
                'user_agent' => $view->userAgent === null ? null : $this->masker->mask($view->userAgent),
                'client_ip' => $view->clientIp,
            ],
            $this->sessions->listForUser($user->id, $session),
        )]);
    }

    public function endSession(#[\SensitiveParameter] Request $request): Response
    {
        $own = $this->own($request, mutating: true);
        if ($own instanceof Response) {
            return $own;
        }
        [$session, $user] = $own;

        // Das Muster der Route lässt nur [1-9][0-9]{0,17} durch; fremde Sitzungen treffen `AND user_id` nicht.
        $id = (int) $request->attributes->getString('id');
        if (!$this->sessions->endById($user->id, $id)) {
            return JsonReply::error(404, 'Sitzung nicht gefunden.');
        }
        $this->audit->record($user->id, 'session.ended', 'session:' . $id);

        $response = JsonReply::noContent();
        if ($session->id === $id) {
            $response->headers->setCookie($this->sessionAuth->expiredCookie($request));
        }

        return $response;
    }

    public function endOthers(#[\SensitiveParameter] Request $request): Response
    {
        $own = $this->own($request, mutating: true);
        if ($own instanceof Response) {
            return $own;
        }
        [$session, $user] = $own;

        $ended = $this->sessions->endOthers($user->id, $session);
        $this->audit->record($user->id, 'user.sessions_ended', 'user:' . $user->id . ' ' . $user->username . ' (' . $ended . ' Sitzungen)');

        return JsonReply::json(['ended' => $ended]);
    }

    /**
     * Sitzung → (CSRF) → aktives Konto. Ohne `allowPasswordChange` wirft die Sitzungsprüfung bei offenem Pflichtwechsel.
     *
     * @return array{0: Session, 1: UserAccount}|Response
     */
    private function own(#[\SensitiveParameter] Request $request, bool $mutating, bool $allowPasswordChange = false): array|Response
    {
        $session = $allowPasswordChange ? $this->sessionAuth->authenticateAllowingPasswordChange($request) : $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if ($mutating && !$this->csrf->check($request, $session->token)) {
            return JsonReply::csrfFailed();
        }
        $user = $this->users->findById($session->userId);

        return $user !== null && $user->isActive ? [$session, $user] : JsonReply::error(401, 'Nicht angemeldet.');
    }

    /**
     * Genau die erlaubten Felder, alle Pflicht; sonst 422 (Meldungen ohne Werte).
     *
     * @param list<string> $fields
     *
     * @return array<mixed>
     *
     * @throws ValidationFailed
     */
    private static function body(#[\SensitiveParameter] Request $request, array $fields): array
    {
        $data = JsonBody::object($request->getContent(), self::MAX_BODY);
        $message = 'Der Anfragekörper muss ein JSON-Objekt sein (höchstens 4 KiB).';
        if ($data === null) {
            throw ValidationFailed::field('body', $message);
        }
        if ($data !== [] && array_is_list($data)) {
            throw ValidationFailed::field('body', $message);
        }
        $errors = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                $errors[$field] = 'Pflichtfeld fehlt.';
            }
        }
        if ($errors === []) {
            foreach (array_keys($data) as $key) {
                if (!in_array((string) $key, $fields, true)) {
                    $errors['body'] = 'Unbekannte Felder im Anfragekörper. Erlaubt: ' . implode(', ', $fields) . '.';
                    break;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return $data;
    }
}
