<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\AuthService;
use Meridian\Auth\CsrfGuard;
use Meridian\Auth\LoginThrottle;
use Meridian\Auth\Session;
use Meridian\Auth\SessionManager;
use Meridian\Config;
use Meridian\Database\Timestamp;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;
use Meridian\User\Actor;
use Meridian\User\AssignmentValidator;
use Meridian\User\DisplayName;
use Meridian\User\RoleCatalog;
use Meridian\User\UserAdminService;
use Meridian\User\UserRepository;
use Meridian\User\UserRequestRefused;
use Meridian\User\UserStatus;
use Meridian\User\UserSummary;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Benutzerverwaltung (docs/decisions/0005, §4.2): Benutzer und Rollen lesen, Benutzer anlegen, ändern, deaktivieren,
 * löschen, Passwort-/2FA-Reset (mit Passwort-Bestätigung) und alle Sitzungen eines Benutzers beenden.
 *
 * Ablauf jedes Endpunkts (mer-security §1): Sitzung (401) → bei ändernden Methoden CSRF/Herkunft (403) →
 * `AccessControl::require(users.manage)` ohne Kategorie (403; der Bereich ist global, es gibt keine Existenz zu
 * verbergen) → Eingaben (422) → {@see UserAdminService}: Laden (404), Selbstaktion/gelöscht (409), `mayManage`/`mayAssign`
 * (403), Passwort-Bestätigung (403/429), Schreiben mit `AdminInvariant` (409) und Audit → Antwort über den
 * {@see UserPresenter}.
 */
final class UserController
{
    private const MAX_BODY = 8192;
    private const MAX_PASSWORD = AuthService::MAX_PASSWORD;

    public function __construct(
        private readonly Config $config,
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly RoleCatalog $roles,
        private readonly SessionManager $sessions,
        private readonly LoginThrottle $throttle,
        private readonly UserPresenter $presenter,
        private readonly UserAdminService $service,
        private readonly AssignmentValidator $assignments,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $id = ['id' => '[1-9][0-9]{0,17}'];
        $kernel->get('users_list', '/api/users', $this->list(...));
        $kernel->get('users_show', '/api/users/{id}', $this->show(...), $id);
        $kernel->get('roles_list', '/api/roles', $this->roleList(...));
        $kernel->post('users_create', '/api/users', $this->create(...));
        $kernel->put('users_update', '/api/users/{id}', $this->update(...), $id);
        $kernel->put('users_assignments', '/api/users/{id}/assignments', $this->replaceAssignments(...), $id);
        $kernel->post('users_deactivate', '/api/users/{id}/deactivate', fn (#[\SensitiveParameter] Request $r): Response => $this->setActive($r, false), $id);
        $kernel->post('users_activate', '/api/users/{id}/activate', fn (#[\SensitiveParameter] Request $r): Response => $this->setActive($r, true), $id);
        $kernel->delete('users_delete', '/api/users/{id}', $this->delete(...), $id);
        $kernel->post('users_password_reset', '/api/users/{id}/password-reset', $this->resetPassword(...), $id);
        $kernel->post('users_2fa_reset', '/api/users/{id}/2fa-reset', $this->resetTwoFactor(...), $id);
        $kernel->post('users_sessions_end', '/api/users/{id}/sessions/end', $this->endSessions(...), $id);
    }

    public function list(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        // all() statt get(): get() wirft bei Arrays wie ?status[]=1 eine Exception (würde als 500 enden).
        $query = $request->query->all();
        $status = null;
        if (array_key_exists('status', $query)) {
            $status = is_string($query['status']) ? UserStatus::tryFrom($query['status']) : null;
            if ($status === null) {
                return JsonReply::error(400, 'Ungültige Abfrage: status ist active, inactive oder deleted.');
            }
        }

        return JsonReply::json(['users' => array_map($this->render(...), $this->users->summaries($status))]);
    }

    public function show(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        $user = $this->users->summary(self::id($request));

        return $user === null
            ? JsonReply::error(404, 'Benutzer nicht gefunden.')
            : JsonReply::json(['user' => $this->render($user)]);
    }

    public function roleList(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        return JsonReply::json($this->presenter->roles($this->roles->all()));
    }

    /**
     * 201 mit dem Benutzer und dem Einmalpasswort — die **einzige** Stelle, an der es im Klartext erscheint.
     */
    public function create(#[\SensitiveParameter] Request $request): Response
    {
        return $this->mutate($request, function (Actor $actor) use ($request): Response {
            $data = self::body($request, ['username', 'display_name', 'assignments'], ['current_password']);
            $username = $data['username'];
            if (!is_string($username) || preg_match('/^[a-z0-9._-]{2,64}$/i', $username) !== 1) {
                throw ValidationFailed::field('username', 'Benutzername: 2 bis 64 Zeichen, nur Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich.');
            }
            $displayName = DisplayName::validate($data['display_name']);
            $assignments = $this->assignments->validate($data['assignments']);

            $created = $this->service->create($actor, $username, $displayName, $assignments, self::password($data));

            return JsonReply::json([
                'user' => $this->render($created->user),
                'initial_password' => $created->password->reveal(),
                'expires_at' => Timestamp::format($created->expiresAt),
            ], 201);
        });
    }

    public function update(#[\SensitiveParameter] Request $request): Response
    {
        return $this->mutate($request, function (Actor $actor) use ($request): Response {
            $data = self::body($request, ['display_name']);
            $displayName = DisplayName::validate($data['display_name']);

            return JsonReply::json(['user' => $this->render($this->service->rename($actor, self::id($request), $displayName))]);
        });
    }

    public function replaceAssignments(#[\SensitiveParameter] Request $request): Response
    {
        return $this->mutate($request, function (Actor $actor) use ($request): Response {
            $data = self::body($request, ['assignments'], ['current_password']);
            $assignments = $this->assignments->validate($data['assignments']);

            return JsonReply::json(['user' => $this->render($this->service->replaceAssignments($actor, self::id($request), $assignments, self::password($data)))]);
        });
    }

    private function setActive(#[\SensitiveParameter] Request $request, bool $active): Response
    {
        return $this->mutate($request, function (Actor $actor) use ($request, $active): Response {
            // Körper leer, `{}` oder `{current_password}` (Pflicht bei Admin-Zielen, sonst ignoriert).
            $data = self::optionalBody($request, ['current_password']);

            return JsonReply::json(['user' => $this->render($this->service->setActive($actor, self::id($request), $active, self::password($data)))]);
        });
    }

    public function delete(#[\SensitiveParameter] Request $request): Response
    {
        return $this->mutate($request, function (Actor $actor) use ($request): Response {
            // Ein DELETE ohne Körper ist ein Feldfehler (Passwort fehlt), kein Formatfehler.
            $data = self::optionalBody($request, ['current_password']);
            $this->service->delete($actor, self::id($request), self::password($data));

            return JsonReply::noContent();
        });
    }

    /**
     * 200 `{initial_password, expires_at}` — neben dem Anlegen die einzige Stelle, an der ein Einmalpasswort im Klartext
     * erscheint. Körper `{current_password}`.
     */
    public function resetPassword(#[\SensitiveParameter] Request $request): Response
    {
        return $this->mutate($request, function (Actor $actor) use ($request): Response {
            $data = self::optionalBody($request, ['current_password']);
            $issued = $this->service->resetPassword($actor, self::id($request), self::password($data));

            return JsonReply::json([
                'initial_password' => $issued->password->reveal(),
                'expires_at' => Timestamp::format($issued->expiresAt),
            ]);
        });
    }

    /** 200 `{user}`. Körper `{current_password}`. */
    public function resetTwoFactor(#[\SensitiveParameter] Request $request): Response
    {
        return $this->mutate($request, function (Actor $actor) use ($request): Response {
            $data = self::optionalBody($request, ['current_password']);

            return JsonReply::json(['user' => $this->render($this->service->resetTwoFactor($actor, self::id($request), self::password($data)))]);
        });
    }

    /** 200 `{ended, user}`. Körper leer oder `{}`. */
    public function endSessions(#[\SensitiveParameter] Request $request): Response
    {
        return $this->mutate($request, function (Actor $actor) use ($request): Response {
            self::optionalBody($request, []);
            $ended = $this->service->endSessions($actor, self::id($request));
            $user = $this->users->summary(self::id($request)) ?? throw UserRequestRefused::notFound();

            return JsonReply::json(['ended' => $ended, 'user' => $this->render($user)]);
        });
    }

    /**
     * Wie {@see body()}, aber ein leerer Körper gilt als `{}` (fehlendes Passwort ist dann ein Feldfehler, kein
     * Formatfehler).
     *
     * @param list<string> $optional
     *
     * @return array<mixed>
     *
     * @throws ValidationFailed
     */
    private static function optionalBody(#[\SensitiveParameter] Request $request, array $optional): array
    {
        return $request->getContent() === '' ? [] : self::body($request, [], $optional);
    }

    /**
     * Gemeinsamer Rahmen der ändernden Endpunkte: Sitzung → CSRF → `users.manage` → Handelnder mit frischen Rechten und
     * Client-IP (Sperre der Passwort-Bestätigung; Proxy-Header ohne Vertrauen → 503 wie bei der Anmeldung).
     *
     * @param callable(Actor): Response $action
     */
    private function mutate(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] callable $action): Response
    {
        $auth = $this->authenticate($request, mutating: true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;

        $ip = ClientIp::forThrottle($request, $this->config);
        if ($ip instanceof Response) {
            return $ip;
        }
        $user = $this->users->findById($session->userId);
        if ($user === null || !$user->isActive) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }

        try {
            return $action(new Actor($user, $grants, $ip));
        } catch (UserRequestRefused $refused) {
            $response = JsonReply::error($refused->status, $refused->getMessage());
            if ($refused->retryAfter !== null) {
                $response->headers->set('Retry-After', (string) $refused->retryAfter);
            }

            return $response;
        }
    }

    /**
     * Der Anfragekörper als JSON-Objekt mit genau den erlaubten Feldern: fehlende Pflichtfelder und unbekannte Felder →
     * 422 (Meldungen ohne Werte).
     *
     * @param list<string> $required
     * @param list<string> $optional
     *
     * @return array<mixed>
     *
     * @throws ValidationFailed
     */
    private static function body(#[\SensitiveParameter] Request $request, array $required, array $optional = []): array
    {
        $data = JsonBody::object($request->getContent(), self::MAX_BODY, 5);
        $message = 'Der Anfragekörper muss ein JSON-Objekt sein (höchstens 8 KiB).';
        if ($data === null) {
            throw ValidationFailed::field('body', $message);
        }
        if ($data !== [] && array_is_list($data)) {
            throw ValidationFailed::field('body', $message);
        }

        $errors = [];
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                $errors[$key] = 'Pflichtfeld fehlt.';
            }
        }
        if ($errors === []) {
            foreach (array_keys($data) as $key) {
                if (!in_array((string) $key, [...$required, ...$optional], true)) {
                    $errors['body'] = 'Unbekannte Felder im Anfragekörper. Erlaubt: ' . implode(', ', [...$required, ...$optional]) . '.';
                    break;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return $data;
    }

    /**
     * `current_password` aus dem Körper: fehlt oder null → null (der Dienst verlangt es, wo nötig), sonst ein String
     * bis 1024 Byte.
     *
     * @param array<mixed> $data
     *
     * @throws ValidationFailed
     */
    private static function password(#[\SensitiveParameter] array $data): ?string
    {
        if (!array_key_exists('current_password', $data) || $data['current_password'] === null) {
            return null;
        }
        if (!is_string($data['current_password']) || strlen($data['current_password']) > self::MAX_PASSWORD) {
            throw ValidationFailed::field('current_password', 'Das Passwort muss ein Text mit höchstens 1024 Byte sein.');
        }

        return $data['current_password'];
    }

    /**
     * @return array<string, mixed>
     */
    private function render(UserSummary $user): array
    {
        return $this->presenter->user($user, $this->sessions->countForUser($user->id), $this->throttle->isUserLocked($user->username));
    }

    /**
     * Sitzung → (CSRF) → `users.manage` ohne Kategorie.
     *
     * @return array{0: Session, 1: list<RoleGrant>}|JsonResponse
     */
    private function authenticate(#[\SensitiveParameter] Request $request, bool $mutating = false): array|JsonResponse
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if ($mutating && !$this->csrf->check($request, $session->token)) {
            return JsonReply::csrfFailed();
        }
        $grants = $this->users->grantsFor($session->userId);
        $this->access->require($grants, Permission::ManageUsers);

        return [$session, $grants];
    }

    private static function id(#[\SensitiveParameter] Request $request): int
    {
        // Das Muster der Route lässt nur [1-9][0-9]{0,17} durch.
        return (int) $request->attributes->getString('id');
    }
}
