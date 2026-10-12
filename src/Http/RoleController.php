<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\CsrfGuard;
use Meridian\Auth\Session;
use Meridian\Config;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;
use Meridian\User\Actor;
use Meridian\User\RoleAdminService;
use Meridian\User\RoleRequestRefused;
use Meridian\User\RoleValidator;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rollenverwaltung (ADR 0006 §5): Folgen-Vorschau lesen, Rollen anlegen, umbenennen, Rechte setzen, löschen.
 *
 * Ablauf jedes Endpunkts: Sitzung (401) → bei ändernden Methoden CSRF/Herkunft (403, `JsonReply::csrfFailed()`) →
 * `require(roles.manage)` ohne Kategorie (403, **vor** Körper und ID: kein 404/422-Orakel) → Körper streng (422) →
 * {@see RoleAdminService} (404, 409, 403, Passwort 422/403/429, Grenzen 429/409, `AdminInvariant` 409) → Antwort nur über
 * den {@see UserPresenter} (Feld-Allowlist, Texte maskiert, `no-store`). Die Liste der Rollen für Zuweisungen
 * (`GET /api/roles`, `users.manage`) bleibt im {@see UserController}.
 */
final class RoleController
{
    private const MAX_BODY = 8192;
    private const MAX_PASSWORD = \Meridian\Auth\AuthService::MAX_PASSWORD;

    public function __construct(
        private readonly Config $config,
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly RoleAdminService $service,
        private readonly RoleValidator $validator,
        private readonly UserPresenter $presenter,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $id = ['id' => '[1-9][0-9]{0,17}'];
        $kernel->get('roles_manage', '/api/roles/manage', $this->manage(...));
        $kernel->post('roles_create', '/api/roles', $this->create(...));
        $kernel->put('roles_update', '/api/roles/{id}', $this->update(...), $id);
        $kernel->put('roles_permissions', '/api/roles/{id}/permissions', $this->setPermissions(...), $id);
        $kernel->delete('roles_delete', '/api/roles/{id}', $this->delete(...), $id);
    }

    /** 200 `{roles, permissions, max_roles}` — je Rolle mit Zuweisungen, Inhabern und was der Handelnde darf. */
    public function manage(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $actor = $this->actor($request, $auth);
        if ($actor instanceof Response) {
            return $actor;
        }

        return JsonReply::json($this->presenter->managedRoles($this->service->usage($actor), RoleAdminService::MAX_ROLES));
    }

    /** 201 `{role}`. Körper `{name, permissions, description?, current_password?}`. */
    public function create(#[\SensitiveParameter] Request $request): Response
    {
        return $this->mutate($request, function (Actor $actor) use ($request): Response {
            $data = self::body($request, ['name', 'permissions'], ['description', 'current_password']);
            $draft = $this->validator->validateCreate($data);
            $role = $this->service->create($actor, $draft, self::password($data));

            return JsonReply::json(['role' => $this->presenter->managedRole($this->service->usageOf($actor, $role->id))], 201);
        });
    }

    /** 200 `{role}`. Körper genau `{name, description}` (description null = keine). */
    public function update(#[\SensitiveParameter] Request $request): Response
    {
        return $this->mutate($request, function (Actor $actor) use ($request): Response {
            $data = self::body($request, ['name', 'description']);
            $fields = $this->validator->validateRename($data);
            $role = $this->service->rename($actor, self::id($request), $fields['name'], $fields['description']);

            return JsonReply::json(['role' => $this->presenter->managedRole($this->service->usageOf($actor, $role->id))]);
        });
    }

    /** 200 `{role}`. Körper `{permissions, current_password?}`; ersetzt die ganze Liste in einer Transaktion. */
    public function setPermissions(#[\SensitiveParameter] Request $request): Response
    {
        return $this->mutate($request, function (Actor $actor) use ($request): Response {
            $data = self::body($request, ['permissions'], ['current_password']);
            $permissions = $this->validator->validatePermissions($data['permissions']);
            $role = $this->service->setPermissions($actor, self::id($request), $permissions, self::password($data));

            return JsonReply::json(['role' => $this->presenter->managedRole($this->service->usageOf($actor, $role->id))]);
        });
    }

    /** 204. Körper leer oder `{}`. */
    public function delete(#[\SensitiveParameter] Request $request): Response
    {
        return $this->mutate($request, function (Actor $actor) use ($request): Response {
            if ($request->getContent() !== '') {
                self::body($request, []);
            }
            $this->service->delete($actor, self::id($request));

            return JsonReply::noContent();
        });
    }

    /**
     * Sitzung → CSRF → `roles.manage` → Handelnder mit frischen Rechten und Client-IP (Sperre der Passwort-Bestätigung).
     *
     * @param callable(Actor): Response $action
     */
    private function mutate(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] callable $action): Response
    {
        $auth = $this->authenticate($request, mutating: true);
        if ($auth instanceof Response) {
            return $auth;
        }
        $actor = $this->actor($request, $auth);
        if ($actor instanceof Response) {
            return $actor;
        }

        try {
            return $action($actor);
        } catch (RoleRequestRefused $refused) {
            $response = JsonReply::json(['error' => $refused->getMessage()] + $refused->counts, $refused->status);
            if ($refused->retryAfter !== null) {
                $response->headers->set('Retry-After', (string) $refused->retryAfter);
            }

            return $response;
        }
    }

    /**
     * @param array{0: Session, 1: list<RoleGrant>} $auth
     */
    private function actor(#[\SensitiveParameter] Request $request, array $auth): Actor|Response
    {
        [$session, $grants] = $auth;
        $ip = ClientIp::forThrottle($request, $this->config);
        if ($ip instanceof Response) {
            return $ip;
        }
        $user = $this->users->findById($session->userId);
        if ($user === null || !$user->isActive) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }

        return new Actor($user, $grants, $ip);
    }

    /**
     * Sitzung → (CSRF) → `roles.manage` ohne Kategorie, bevor Körper oder ID gelesen werden.
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
        $this->access->require($grants, Permission::ManageRoles);

        return [$session, $grants];
    }

    /**
     * Der Anfragekörper als JSON-Objekt mit genau den erlaubten Feldern (fehlende Pflichtfelder, unbekannte Felder → 422,
     * Meldungen ohne Werte).
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
        $data = JsonBody::object($request->getContent(), self::MAX_BODY, 4);
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
                    $errors['body'] = 'Unbekannte Felder im Anfragekörper. Erlaubt: ' . ([...$required, ...$optional] === [] ? 'keine' : implode(', ', [...$required, ...$optional])) . '.';
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

    private static function id(#[\SensitiveParameter] Request $request): int
    {
        // Das Muster der Route lässt nur [1-9][0-9]{0,17} durch.
        return (int) $request->attributes->getString('id');
    }
}
