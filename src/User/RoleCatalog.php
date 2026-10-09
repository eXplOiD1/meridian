<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Database\Connection;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;

/**
 * Liest Rollen und ihre Rechte. Im MVP nur lesend (E1): Rollen und Rechte stehen in den Migrationen.
 */
final class RoleCatalog
{
    private const ROLES_SQL = 'SELECT r.id, r.name, r.is_builtin, rp.permission FROM roles r
      LEFT JOIN role_permissions rp ON rp.role_id = r.id ORDER BY r.id, rp.permission';

    private const CATEGORY_NAMES_SQL = 'SELECT id, name FROM categories WHERE id IN (SELECT value FROM json_each(:ids)) ORDER BY name COLLATE NOCASE, id';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<Role>
     */
    public function all(): array
    {
        /** @var array<int, array{name: string, builtin: bool, permissions: list<Permission>}> $byId */
        $byId = [];
        foreach ($this->db->fetchAll(self::ROLES_SQL) as $row) {
            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            if (!is_int($id) || !is_string($name)) {
                continue;
            }
            $byId[$id] ??= ['name' => $name, 'builtin' => ($row['is_builtin'] ?? 0) === 1, 'permissions' => []];
            // Unbekannte Rechte (z. B. aus einer neueren Version) werden nie gewährt und hier weggelassen.
            $permission = isset($row['permission']) && is_string($row['permission']) ? Permission::tryFrom($row['permission']) : null;
            if ($permission !== null) {
                $byId[$id]['permissions'][] = $permission;
            }
        }

        $roles = [];
        foreach ($byId as $id => $role) {
            $roles[] = new Role($id, $role['name'], $role['builtin'], $role['permissions']);
        }

        return $roles;
    }

    public function find(int $id): ?Role
    {
        foreach ($this->all() as $role) {
            if ($role->id === $id) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Namen der vorhandenen Kategorien zu den IDs (ID => Name). Fehlende IDs fehlen im Ergebnis.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function categoryNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach ($this->db->fetchAll(self::CATEGORY_NAMES_SQL, ['ids' => json_encode($ids, JSON_THROW_ON_ERROR)]) as $row) {
            if (isset($row['id'], $row['name']) && is_int($row['id']) && is_string($row['name'])) {
                $names[$row['id']] = $row['name'];
            }
        }

        return $names;
    }

    /**
     * Das Recht, das die Zuweisung gewähren würde — Eingabe für `GrantPolicy::mayAssign()` (Rechte der Rolle,
     * Kategorienamen bzw. null für „alle“). Unbekannte Rolle oder Kategorie → Ausnahme (vorher validieren).
     */
    public function grantFor(Assignment $assignment): RoleGrant
    {
        $role = $this->find($assignment->roleId) ?? throw new \InvalidArgumentException('Rolle unbekannt.');
        if ($assignment->allCategories) {
            return new RoleGrant($role->name, $role->permissions, null);
        }
        $names = $this->categoryNames($assignment->categoryIds);
        if (count($names) !== count($assignment->categoryIds)) {
            throw new \InvalidArgumentException('Kategorie unbekannt.');
        }

        return new RoleGrant($role->name, $role->permissions, array_values($names));
    }
}
