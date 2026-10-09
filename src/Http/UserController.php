<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\CsrfGuard;
use Meridian\Auth\LoginThrottle;
use Meridian\Auth\Session;
use Meridian\Auth\SessionManager;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;
use Meridian\User\RoleCatalog;
use Meridian\User\UserRepository;
use Meridian\User\UserStatus;
use Meridian\User\UserSummary;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Benutzerverwaltung (docs/decisions/0005, §4.2): Benutzer und Rollen lesen.
 *
 * Ablauf jedes Endpunkts (mer-security §1): Sitzung (401) → bei ändernden Methoden CSRF/Herkunft (403) →
 * `AccessControl::require(users.manage)` ohne Kategorie (403; der Bereich ist global, es gibt keine Existenz zu
 * verbergen) → Eingaben → Laden (404) → Antwort über den {@see UserPresenter}.
 */
final class UserController
{
    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly RoleCatalog $roles,
        private readonly SessionManager $sessions,
        private readonly LoginThrottle $throttle,
        private readonly UserPresenter $presenter,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $id = ['id' => '[1-9][0-9]{0,17}'];
        $kernel->get('users_list', '/api/users', $this->list(...));
        $kernel->get('users_show', '/api/users/{id}', $this->show(...), $id);
        $kernel->get('roles_list', '/api/roles', $this->roleList(...));
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
            return JsonReply::error(403, 'CSRF-Prüfung fehlgeschlagen.');
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
