<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Auth\AuditLog;
use Meridian\Auth\AuthService;
use Meridian\Auth\Clock;
use Meridian\Auth\TooManyAttempts;
use Meridian\Database\Connection;
use Meridian\Http\ValidationFailed;
use Meridian\Security\AccessDenied;
use Meridian\Security\GrantPolicy;
use Meridian\Security\Permission;

/**
 * Rollenverwaltung schreibend (ADR 0006 E3–E5, E7, §9.1). Alle Rechtsprüfungen bauen auf `AccessControl::can()` auf
 * ({@see GrantPolicy}), nie auf Rollennamen.
 *
 * Reihenfolge jeder Aktion (§5): `roles.manage` (403) → Laden (404) → Standard-/Admin-Rolle (409/422) → Selbstbezug (409,
 * Ausnahme F5) → `mayDefineRole` alt ∪ neu (403) → `mayManage` je Inhaber (403, F8) → beschränkte Zuweisungen bei neuem
 * gefährlichem Recht (409, E3) → Passwort-Bestätigung **einmal** je Anfrage, wenn ein gefährliches Recht dazukommt oder
 * wegfällt (F6) → **eine** `Connection::immediate()`-Transaktion: frische Rechte des Handelnden, Rolle neu laden, alle
 * Prüfungen wiederholen ({@see recheck()}), Grenzen, gezielt schreiben, `AdminInvariant::assertHeld()`, Audit.
 */
final class RoleAdminService
{
    /** Höchstens so viele Rollen insgesamt (F7). */
    public const MAX_ROLES = 50;
    /** Rollenänderungen (anlegen, umbenennen, Rechte setzen, löschen) je Handelndem und Stunde (F7). */
    public const CHANGE_LIMIT = 60;
    private const WINDOW_SECONDS = 3600;

    private const COUNT_SQL = 'SELECT COUNT(*) AS n FROM audit_log WHERE user_id = :u AND created_at > :since
      AND action IN (\'role.created\', \'role.renamed\', \'role.permissions_changed\', \'role.deleted\')';
    private const EDGE_SQL = 'SELECT created_at FROM audit_log WHERE user_id = :u AND created_at > :since
      AND action IN (\'role.created\', \'role.renamed\', \'role.permissions_changed\', \'role.deleted\')
      ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET :offset';

    public const MESSAGE_NAME_TAKEN = 'Eine Rolle mit diesem Namen gibt es schon (ohne Rücksicht auf Groß- und Kleinschreibung). Einen anderen Namen wählen.';
    public const MESSAGE_BUILTIN_NAME = 'Der Name einer Standardrolle ist fest.';
    public const MESSAGE_BUILTIN_DESCRIPTION = 'Die Beschreibung einer Standardrolle ist fest.';

    public function __construct(
        private readonly Connection $db,
        private readonly RoleCatalog $roles,
        private readonly RoleRepository $repository,
        private readonly UserRepository $users,
        private readonly GrantPolicy $policy,
        private readonly AdminInvariant $invariant,
        private readonly AuditLog $audit,
        private readonly AuthService $auth,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Folgen-Vorschau aller Rollen aus Sicht des Handelnden (`GET /api/roles/manage`).
     *
     * @return list<RoleUsage>
     *
     * @throws AccessDenied
     */
    public function usage(Actor $actor): array
    {
        $this->policy->requireRoleManager($actor->grants);

        return array_map(fn (Role $role): RoleUsage => $this->describeUsage($actor, $role), $this->roles->all());
    }

    /**
     * @throws AccessDenied
     * @throws RoleRequestRefused 404
     */
    public function usageOf(Actor $actor, int $roleId): RoleUsage
    {
        $this->policy->requireRoleManager($actor->grants);

        return $this->describeUsage($actor, $this->load($roleId));
    }

    /**
     * Legt eine eigene Rolle an. Ein gefährliches Recht verlangt das Passwort des Handelnden (einmal).
     *
     * @throws AccessDenied       403 ohne `roles.manage`
     * @throws RoleRequestRefused 403 (Rechte, die der Handelnde nicht hat; Passwort falsch), 409 (50 Rollen), 429
     * @throws ValidationFailed   Name vergeben, Passwort fehlt
     * @throws TooManyAttempts    Passwort-Bestätigung gesperrt
     */
    public function create(Actor $actor, RoleDraft $draft, #[\SensitiveParameter] ?string $currentPassword): Role
    {
        $this->policy->requireRoleManager($actor->grants);
        $draft = new RoleDraft($draft->name, $draft->description, self::ordered($draft->permissions));
        $this->assertMayDefine($actor, [], $draft->permissions);
        if ($this->repository->nameTaken($draft->name)) {
            throw ValidationFailed::field('name', self::MESSAGE_NAME_TAKEN);
        }
        $needsPassword = self::hasDangerous($draft->permissions);
        if ($needsPassword) {
            $this->confirm($actor, $currentPassword);
        }

        $id = $this->db->immediate(function () use ($actor, $draft): int {
            $actor = $this->recheck($actor);
            $this->assertMayDefine($actor, [], $draft->permissions);
            $this->assertWithinLimit($actor);
            if ($this->repository->count() >= self::MAX_ROLES) {
                throw RoleRequestRefused::tooManyRoles(self::MAX_ROLES);
            }
            if ($this->repository->nameTaken($draft->name)) {
                throw ValidationFailed::field('name', self::MESSAGE_NAME_TAKEN);
            }
            $id = $this->repository->insert($draft->name, $draft->description, $draft->permissions);
            $this->invariant->assertHeld();
            $this->audit->record($actor->user->id, 'role.created', 'role:' . $id . ' ' . $draft->name . ' [' . self::keys($draft->permissions) . ']');

            return $id;
        });

        return $this->load($id);
    }

    /**
     * Ändert Name und Beschreibung einer eigenen Rolle (auch einer, die dem Handelnden zugewiesen ist: ändert keine
     * Rechte, F5). Bei Standardrollen sind beide fest (F1, F2): gleiche Werte sind kein Fehler und ändern nichts.
     *
     * @throws AccessDenied
     * @throws RoleRequestRefused 404, 403, 429
     * @throws ValidationFailed   Name vergeben, Name/Beschreibung einer Standardrolle
     */
    public function rename(Actor $actor, int $roleId, string $name, ?string $description): Role
    {
        $this->policy->requireRoleManager($actor->grants);
        $role = $this->load($roleId);
        self::assertRenamable($role, $name, $description);
        $this->assertMayDefine($actor, $role->permissions, $role->permissions);
        $this->assertHoldersManageable($actor, $role->id);
        if ($this->repository->nameTaken($name, $role->id)) {
            throw ValidationFailed::field('name', self::MESSAGE_NAME_TAKEN);
        }

        return $this->db->immediate(function () use ($actor, $roleId, $name, $description): Role {
            $actor = $this->recheck($actor);
            $role = $this->load($roleId);
            self::assertRenamable($role, $name, $description);
            $this->assertMayDefine($actor, $role->permissions, $role->permissions);
            $this->assertHoldersManageable($actor, $role->id);
            $nameChanged = $role->name !== $name;
            $descriptionChanged = $role->description !== $description;
            if (!$nameChanged && !$descriptionChanged) {
                return $role;
            }
            $this->assertWithinLimit($actor);
            if ($this->repository->nameTaken($name, $role->id)) {
                throw ValidationFailed::field('name', self::MESSAGE_NAME_TAKEN);
            }
            if (!$this->repository->rename($role->id, $name, $description)) {
                throw RoleRequestRefused::changedMeanwhile();
            }
            $target = 'role:' . $role->id . ' ' . ($nameChanged ? $role->name . ' → ' . $name : $role->name);
            $this->audit->record($actor->user->id, 'role.renamed', $target . ($descriptionChanged ? ($nameChanged ? ', ' : ': ') . 'Beschreibung geändert' : ''));

            return $this->load($role->id);
        });
    }

    /**
     * Ersetzt die Rechte einer Rolle (gezielt: fehlende entfernen, neue einfügen) — in **einer** Transaktion und mit
     * höchstens **einer** Passwort-Bestätigung für alle Änderungen der Anfrage (F6). Die Änderung wirkt sofort für alle
     * Inhaber (E6).
     *
     * @param list<Permission> $permissions die ganze neue Liste
     *
     * @throws AccessDenied
     * @throws RoleRequestRefused 404, 403 (Rechte fehlen, Inhaber mit mehr Rechten, Passwort falsch), 409 (Admin-Rolle,
     *                            eigene Rolle, beschränkte Zuweisungen, inzwischen geändert), 429
     * @throws LastAdminRemoved   der letzte Admin verlöre ein Recht (Rollback, 409)
     * @throws ValidationFailed   Passwort fehlt
     * @throws TooManyAttempts    Passwort-Bestätigung gesperrt
     */
    public function setPermissions(Actor $actor, int $roleId, array $permissions, #[\SensitiveParameter] ?string $currentPassword): Role
    {
        $this->policy->requireRoleManager($actor->grants);
        $permissions = self::ordered($permissions);
        $role = $this->load($roleId);
        $this->assertPermissionsEditable($actor, $role);
        $this->assertMayDefine($actor, $role->permissions, $permissions);
        $this->assertHoldersManageable($actor, $role->id);
        [$added, $removed] = self::diff($role->permissions, $permissions);
        $this->assertDangerousFitsAssignments($role->id, $added);
        $needsPassword = self::hasDangerous($added) || self::hasDangerous($removed);
        if ($needsPassword) {
            // Genau eine Bestätigung für die ganze Anfrage, vor der Transaktion (die Sperre öffnet eine eigene).
            $this->confirm($actor, $currentPassword);
        }

        return $this->db->immediate(function () use ($actor, $roleId, $permissions, $needsPassword): Role {
            $actor = $this->recheck($actor);
            $role = $this->load($roleId);
            $this->assertPermissionsEditable($actor, $role);
            $this->assertMayDefine($actor, $role->permissions, $permissions);
            $this->assertHoldersManageable($actor, $role->id);
            [$added, $removed] = self::diff($role->permissions, $permissions);
            $this->assertDangerousFitsAssignments($role->id, $added);
            // Inzwischen geändert, sodass jetzt ein gefährliches Recht betroffen ist, das nicht bestätigt wurde.
            if (!$needsPassword && (self::hasDangerous($added) || self::hasDangerous($removed))) {
                throw RoleRequestRefused::changedMeanwhile();
            }
            if ($added === [] && $removed === []) {
                return $role;
            }
            $this->assertWithinLimit($actor);
            foreach ($removed as $permission) {
                $this->repository->removePermission($role->id, $permission);
            }
            foreach ($added as $permission) {
                $this->repository->addPermission($role->id, $permission);
            }
            $this->invariant->assertHeld();
            $changes = [...array_map(static fn (Permission $p): string => '+' . $p->value, $added), ...array_map(static fn (Permission $p): string => '−' . $p->value, $removed)];
            $this->audit->record(
                $actor->user->id,
                'role.permissions_changed',
                'role:' . $role->id . ' ' . $role->name . ': ' . implode(' ', $changes) . ' (betrifft ' . count($this->repository->holders($role->id)) . ' Benutzer)',
            );

            return $this->load($role->id);
        });
    }

    /**
     * Löscht eine eigene Rolle ohne Zuweisungen (E5, F4).
     *
     * @throws AccessDenied
     * @throws RoleRequestRefused 404, 403, 409 (Standardrolle, eigene Rolle, Zuweisungen), 429
     */
    public function delete(Actor $actor, int $roleId): void
    {
        $this->policy->requireRoleManager($actor->grants);
        $this->assertDeletable($actor, $this->load($roleId));

        $this->db->immediate(function () use ($actor, $roleId): void {
            $actor = $this->recheck($actor);
            $role = $this->load($roleId);
            $this->assertDeletable($actor, $role);
            $this->assertWithinLimit($actor);
            if (!$this->repository->delete($role->id)) {
                throw RoleRequestRefused::changedMeanwhile();
            }
            $this->invariant->assertHeld();
            $this->audit->record($actor->user->id, 'role.deleted', 'role:' . $role->id . ' ' . $role->name);
        });
    }

    // --- Prüfungen -------------------------------------------------------------------------------

    /**
     * @throws RoleRequestRefused 404
     */
    private function load(int $roleId): Role
    {
        return $this->roles->find($roleId) ?? throw RoleRequestRefused::notFound();
    }

    /**
     * In der Schreibtransaktion: Rechte des Handelnden frisch laden (inaktiv, gelöscht, Pflichtwechsel → leer) und
     * `roles.manage` verlangen. Der Aufrufer wiederholt alle übrigen Prüfungen mit dem zurückgegebenen Handelnden.
     *
     * @throws AccessDenied
     */
    private function recheck(Actor $actor): Actor
    {
        $fresh = new Actor($actor->user, $this->users->grantsFor($actor->user->id), $actor->ip);
        $this->policy->requireRoleManager($fresh->grants);

        return $fresh;
    }

    private function describeUsage(Actor $actor, Role $role): RoleUsage
    {
        $held = in_array($actor->user->id, $this->repository->holders($role->id), true);

        return new RoleUsage(
            $role,
            $this->repository->countAssignments($role->id),
            $this->repository->countRestrictedAssignments($role->id),
            $this->repository->countActiveUsers($role->id),
            $held,
            $role->permissionsEditable() && (!$held || $this->isFullAdmin($actor)),
        );
    }

    /**
     * Ausnahme F5: Wer die Admin-Rolle uneingeschränkt hat und damit jedes Recht besitzt, darf auch die Rechte einer
     * anderen ihm zugewiesenen Rolle ändern (er behält über die feste Admin-Rolle alles).
     */
    private function isFullAdmin(Actor $actor): bool
    {
        return $this->invariant->grantsAll($actor->grants) && $this->repository->holdsUnrestrictedAdminRole($actor->user->id);
    }

    /**
     * @throws RoleRequestRefused 409
     */
    private function assertPermissionsEditable(Actor $actor, Role $role): void
    {
        if ($role->isAdminRole() || !$role->permissionsEditable()) {
            throw RoleRequestRefused::adminFixed();
        }
        if (in_array($actor->user->id, $this->repository->holders($role->id), true) && !$this->isFullAdmin($actor)) {
            throw RoleRequestRefused::ownRole();
        }
    }

    /**
     * @throws RoleRequestRefused 409, 403
     */
    private function assertDeletable(Actor $actor, Role $role): void
    {
        if ($role->isAdminRole()) {
            throw RoleRequestRefused::adminFixed();
        }
        if ($role->isBuiltin()) {
            throw RoleRequestRefused::builtinNotDeletable();
        }
        if (in_array($actor->user->id, $this->repository->holders($role->id), true)) {
            throw RoleRequestRefused::ownRole();
        }
        $this->assertMayDefine($actor, $role->permissions, []);
        $this->assertHoldersManageable($actor, $role->id);
        $assignments = $this->repository->countAssignments($role->id);
        if ($assignments > 0) {
            throw RoleRequestRefused::stillAssigned($assignments);
        }
    }

    /**
     * @throws ValidationFailed 422 am Feld, wenn Name oder Beschreibung einer Standardrolle geändert werden sollen
     */
    private static function assertRenamable(Role $role, string $name, ?string $description): void
    {
        if (!$role->isBuiltin()) {
            return;
        }
        $errors = [];
        if ($name !== $role->name) {
            $errors['name'] = self::MESSAGE_BUILTIN_NAME;
        }
        if ($description !== $role->description) {
            $errors['description'] = self::MESSAGE_BUILTIN_DESCRIPTION;
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
    }

    /**
     * @param list<Permission> $old
     * @param list<Permission> $new
     *
     * @throws RoleRequestRefused 403
     */
    private function assertMayDefine(Actor $actor, array $old, array $new): void
    {
        if (!$this->policy->mayDefineRole($actor->grants, $old, $new)) {
            throw RoleRequestRefused::mayNotDefine();
        }
    }

    /**
     * F8: Eine Rollenänderung ist eine Änderung an allen Inhabern. Jeder Inhaber (auch inaktiv, mit Einmalpasswort) darf
     * nichts zugewiesen haben, was dem Handelnden fehlt.
     *
     * @throws RoleRequestRefused 403
     */
    private function assertHoldersManageable(Actor $actor, int $roleId): void
    {
        foreach ($this->repository->holders($roleId) as $holder) {
            if (!$this->policy->mayManage($actor->grants, $this->users->assignedGrantsFor($holder))) {
                throw RoleRequestRefused::holderHasMore();
            }
        }
    }

    /**
     * E3: Ein gefährliches Recht gilt nur bei Zuweisungen für alle Kategorien. Kommt eines dazu, darf die Rolle keine
     * beschränkte Zuweisung haben.
     *
     * @param list<Permission> $added
     *
     * @throws RoleRequestRefused 409 mit Anzahl
     */
    private function assertDangerousFitsAssignments(int $roleId, array $added): void
    {
        if (!self::hasDangerous($added)) {
            return;
        }
        $restricted = $this->repository->countRestrictedAssignments($roleId);
        if ($restricted > 0) {
            throw RoleRequestRefused::restrictedAssignments($restricted);
        }
    }

    /**
     * Passwort des Handelnden bestätigen (F6, wie ADR 0005 E7). Fehlt es → 422; falsch → 403; Sperre → 429.
     *
     * @throws ValidationFailed
     * @throws RoleRequestRefused
     * @throws TooManyAttempts
     */
    private function confirm(Actor $actor, #[\SensitiveParameter] ?string $currentPassword): void
    {
        if ($currentPassword === null) {
            throw ValidationFailed::field('current_password', 'Zur Bestätigung das eigene Passwort angeben (ein gefährliches Recht kommt dazu oder fällt weg).');
        }
        if (!$this->auth->confirmPassword($actor->user, $currentPassword, $actor->ip)) {
            throw RoleRequestRefused::wrongPassword();
        }
    }

    /**
     * Höchstens CHANGE_LIMIT Rollenänderungen je Handelndem und Stunde (aus dem Audit-Log, in der Schreibtransaktion).
     *
     * @throws RoleRequestRefused 429
     */
    private function assertWithinLimit(Actor $actor): void
    {
        $now = $this->clock->now();
        $params = ['u' => $actor->user->id, 'since' => $now->modify('-' . self::WINDOW_SECONDS . ' seconds')->format('c')];
        $count = $this->db->fetchOne(self::COUNT_SQL, $params);
        if ($count !== null && isset($count['n']) && is_int($count['n']) && $count['n'] < self::CHANGE_LIMIT) {
            return;
        }

        $edge = $this->db->fetchOne(self::EDGE_SQL, $params + ['offset' => self::CHANGE_LIMIT - 1]);
        $retry = self::WINDOW_SECONDS;
        if ($edge !== null && isset($edge['created_at']) && is_string($edge['created_at'])) {
            $retry = max(1, (new \DateTimeImmutable($edge['created_at']))->getTimestamp() + self::WINDOW_SECONDS - $now->getTimestamp());
        }

        throw new RoleRequestRefused(429, 'Zu viele Rollenänderungen in der letzten Stunde (höchstens ' . self::CHANGE_LIMIT . '). Später erneut versuchen.', $retry);
    }

    /**
     * @param list<Permission> $old
     * @param list<Permission> $new
     *
     * @return array{0: list<Permission>, 1: list<Permission>} [hinzu, weg] in Katalog-Reihenfolge
     */
    private static function diff(array $old, array $new): array
    {
        $added = [];
        $removed = [];
        foreach (Permission::cases() as $permission) {
            $before = in_array($permission, $old, true);
            $after = in_array($permission, $new, true);
            if ($after && !$before) {
                $added[] = $permission;
            } elseif ($before && !$after) {
                $removed[] = $permission;
            }
        }

        return [$added, $removed];
    }

    /**
     * Katalog-Reihenfolge ohne Doppelte.
     *
     * @param list<Permission> $permissions
     *
     * @return list<Permission>
     */
    private static function ordered(array $permissions): array
    {
        return array_values(array_filter(Permission::cases(), static fn (Permission $p): bool => in_array($p, $permissions, true)));
    }

    /**
     * @param list<Permission> $permissions
     */
    private static function hasDangerous(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($permission->isDangerous()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Permission> $permissions
     */
    private static function keys(array $permissions): string
    {
        return implode(', ', array_map(static fn (Permission $p): string => $p->value, $permissions));
    }
}
