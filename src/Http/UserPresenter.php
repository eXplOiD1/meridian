<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Security\Permission;
use Meridian\Security\SecretMasker;
use Meridian\User\AssignmentValidator;
use Meridian\User\AssignmentView;
use Meridian\User\Role;
use Meridian\User\RoleUsage;
use Meridian\User\UserSummary;

/**
 * JSON von Benutzern und Rollen für die Verwaltung (ADR 0005, §4.4): eine Feld-Allowlist, alle Texte durch den
 * {@see SecretMasker}. Nie `password_hash`, `totp_secret_enc`, Wiederherstellungscodes, `token_hash`, Browser oder IP
 * einer Sitzung — der {@see UserSummary} trägt sie gar nicht. Neues Feld → hier eintragen und im Test die
 * Feldreihenfolge prüfen.
 */
final class UserPresenter
{
    public function __construct(private readonly SecretMasker $masker)
    {
    }

    /**
     * @param int  $sessionCount gültige Sitzungen (Admins sehen bei Fremden nur die Anzahl)
     * @param bool $locked       Anmeldung für den Benutzernamen gesperrt
     *
     * @return array<string, mixed>
     */
    public function user(UserSummary $user, int $sessionCount, bool $locked): array
    {
        return [
            'id' => $user->id,
            'username' => $this->masker->mask($user->username),
            'display_name' => $this->masker->mask($user->displayName),
            'status' => $user->status->value,
            'totp_enabled' => $user->totpEnabled,
            'password_change_required' => $user->passwordChangeRequired,
            'locked' => $locked,
            'last_login_at' => $user->lastLoginAt,
            'created_at' => $user->createdAt,
            'session_count' => $sessionCount,
            'assignments' => array_map($this->assignment(...), $user->assignments),
        ];
    }

    /**
     * @param list<Role> $roles
     *
     * @return array{roles: list<array<string, mixed>>, permissions: list<array<string, mixed>>, max_assignments: int}
     */
    public function roles(array $roles): array
    {
        return [
            'roles' => array_map($this->role(...), $roles),
            'permissions' => self::catalog(),
            'max_assignments' => AssignmentValidator::MAX_ASSIGNMENTS,
        ];
    }

    /**
     * Rollenverwaltung (`GET /api/roles/manage`, ADR 0006 §5): je Rolle zusätzlich Zuweisungen und was der Handelnde darf.
     *
     * @param list<RoleUsage> $usage
     *
     * @return array{roles: list<array<string, mixed>>, permissions: list<array<string, mixed>>, max_roles: int}
     */
    public function managedRoles(array $usage, int $maxRoles): array
    {
        return [
            'roles' => array_map($this->managedRole(...), $usage),
            'permissions' => self::catalog(),
            'max_roles' => $maxRoles,
        ];
    }

    /**
     * Feld-Allowlist in fester Reihenfolge (§5). Name und Beschreibung sind freier Text: maskiert.
     *
     * @return array<string, mixed>
     */
    public function managedRole(RoleUsage $usage): array
    {
        return $this->role($usage->role) + [
            'permissions_editable' => $usage->permissionsEditable,
            'deletable' => $usage->deletable(),
            'assignments' => $usage->assignments,
            'restricted_assignments' => $usage->restrictedAssignments,
            'active_users' => $usage->activeUsers,
            'held_by_me' => $usage->heldByActor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function role(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $this->masker->mask($role->name),
            'description' => $role->description === null ? null : $this->masker->mask($role->description),
            'builtin' => $role->isBuiltin(),
            'builtin_key' => $role->builtinKey?->value,
            // In der Reihenfolge des Katalogs, damit die Matrix stabil bleibt.
            'permissions' => array_values(array_map(
                static fn (Permission $p): string => $p->value,
                array_filter(Permission::cases(), static fn (Permission $p): bool => in_array($p, $role->permissions, true)),
            )),
        ];
    }

    /**
     * @return list<array{key: string, dangerous: bool, scoped: bool}>
     */
    private static function catalog(): array
    {
        return array_map(
            static fn (Permission $p): array => ['key' => $p->value, 'dangerous' => $p->isDangerous(), 'scoped' => $p->isCategoryScoped()],
            Permission::cases(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function assignment(AssignmentView $assignment): array
    {
        $categories = [];
        foreach ($assignment->categories as $id => $name) {
            $categories[] = ['id' => $id, 'name' => $this->masker->mask($name)];
        }

        return [
            'role_id' => $assignment->roleId,
            'role' => $this->masker->mask($assignment->role),
            'all_categories' => $assignment->allCategories,
            'categories' => $categories,
            'effective' => $assignment->effective(),
        ];
    }
}
