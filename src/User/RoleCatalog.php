<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Database\Connection;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;

/**
 * Liest Rollen und ihre Rechte (nur lesend; geschrieben wird nur über `RoleRepository` aus `RoleAdminService`,
 * ADR 0006). Standardrollen über ihre Kennung ({@see builtin()}), nie über den Namen. Kein Cache: jede Abfrage liest
 * frisch, damit eine Rollenänderung sofort wirkt (E6).
 */
final class RoleCatalog
{
    private const ROLES_SQL = 'SELECT r.id, r.name, r.is_builtin, r.builtin_key, r.description, rp.permission FROM roles r
      LEFT JOIN role_permissions rp ON rp.role_id = r.id ORDER BY r.id, rp.permission';

    private const ID_BY_NAME_SQL = 'SELECT id FROM roles WHERE name = :name COLLATE NOCASE';

    private const ID_BY_KEY_SQL = 'SELECT id FROM roles WHERE builtin_key = :k';

    private const CATEGORY_NAMES_SQL = 'SELECT id, name FROM categories WHERE id IN (SELECT value FROM json_each(:ids)) ORDER BY name COLLATE NOCASE, id';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<Role>
     */
    public function all(): array
    {
        /** @var array<int, array{name: string, builtin: bool, key: BuiltinRole|null, description: string|null, permissions: list<Permission>}> $byId */
        $byId = [];
        foreach ($this->db->fetchAll(self::ROLES_SQL) as $row) {
            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            if (!is_int($id) || !is_string($name)) {
                continue;
            }
            $key = isset($row['builtin_key']) && is_string($row['builtin_key']) ? BuiltinRole::tryFrom($row['builtin_key']) : null;
            $description = isset($row['description']) && is_string($row['description']) ? $row['description'] : null;
            $byId[$id] ??= ['name' => $name, 'builtin' => ($row['is_builtin'] ?? 0) === 1, 'key' => $key, 'description' => $description, 'permissions' => []];
            // Unbekannte Rechte (z. B. aus einer neueren Version) werden nie gewährt und hier weggelassen.
            $permission = isset($row['permission']) && is_string($row['permission']) ? Permission::tryFrom($row['permission']) : null;
            if ($permission !== null) {
                $byId[$id]['permissions'][] = $permission;
            }
        }

        $roles = [];
        foreach ($byId as $id => $role) {
            // In Katalog-Reihenfolge (Permission::cases()), wie in Antworten und Audit.
            $permissions = array_values(array_filter(Permission::cases(), static fn (Permission $p): bool => in_array($p, $role['permissions'], true)));
            $roles[] = new Role($id, $role['name'], $role['builtin'], $permissions, $role['key'], $role['description']);
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

    /** Rolle nach Namen ohne Rücksicht auf Schreibung (CLI `user:create --role=`, auch eigene Rollen). */
    public function findByName(string $name): ?Role
    {
        $row = $this->db->fetchOne(self::ID_BY_NAME_SQL, ['name' => $name]);

        return $row !== null && isset($row['id']) && is_int($row['id']) ? $this->find($row['id']) : null;
    }

    /**
     * Eine Standardrolle über ihre Kennung. Fehlt sie, ist die Datenbank nicht auf Stand (Migration 0013).
     *
     * @throws \RuntimeException
     */
    public function builtin(BuiltinRole $key): Role
    {
        $row = $this->db->fetchOne(self::ID_BY_KEY_SQL, ['k' => $key->value]);
        $role = $row !== null && isset($row['id']) && is_int($row['id']) ? $this->find($row['id']) : null;

        return $role ?? throw new \RuntimeException('Standardrolle fehlt. Zuerst `bin/meridian migrate` ausführen.');
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
