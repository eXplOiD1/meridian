<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Database\Connection;
use Meridian\Security\Permission;

/**
 * Schreibzugriffe auf Rollen (ADR 0006 §4) **ohne** eigene Transaktion: der {@see RoleAdminService} öffnet die
 * `immediate()`-Transaktion und prüft danach `AdminInvariant`. Lesen der Rollen selbst bleibt im {@see RoleCatalog}.
 * Rechte werden gezielt eingefügt und entfernt, nie geleert und neu befüllt; die Trigger aus 0013 sind die zweite
 * Schicht (Standardrollen, Admin-Rechte, unbekannte Rechte).
 */
final class RoleRepository
{
    private const ASSIGNMENTS_SQL = 'SELECT COUNT(*) AS n FROM user_roles WHERE role_id = :r';

    private const RESTRICTED_SQL = 'SELECT COUNT(*) AS n FROM user_roles ur WHERE ur.role_id = :r
        AND (ur.all_categories <> 1 OR EXISTS (SELECT 1 FROM user_role_categories urc WHERE urc.user_role_id = ur.id))';

    private const ACTIVE_USERS_SQL = 'SELECT COUNT(DISTINCT ur.user_id) AS n FROM user_roles ur JOIN users u ON u.id = ur.user_id
        WHERE ur.role_id = :r AND u.is_active = 1 AND u.deleted_at IS NULL';

    private const HOLDERS_SQL = 'SELECT DISTINCT user_id FROM user_roles WHERE role_id = :r ORDER BY user_id';

    private const HOLDS_FULL_ADMIN_SQL = 'SELECT 1 AS ok FROM user_roles ur JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = :u AND r.builtin_key = \'admin\' AND ur.all_categories = 1
          AND NOT EXISTS (SELECT 1 FROM user_role_categories urc WHERE urc.user_role_id = ur.id)';

    private const NAME_TAKEN_SQL = 'SELECT id FROM roles WHERE name = :n COLLATE NOCASE AND (:id IS NULL OR id <> :id)';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param list<Permission> $permissions
     */
    public function insert(string $name, ?string $description, array $permissions): int
    {
        $this->db->execute('INSERT INTO roles (name, description) VALUES (:n, :d)', ['n' => $name, 'd' => $description]);
        $id = $this->db->lastInsertId();
        foreach ($permissions as $permission) {
            $this->addPermission($id, $permission);
        }

        return $id;
    }

    /** Name und Beschreibung einer eigenen Rolle. @return bool ob eine Zeile geändert wurde */
    public function rename(int $roleId, string $name, ?string $description): bool
    {
        return $this->db->execute(
            'UPDATE roles SET name = :n, description = :d WHERE id = :id AND builtin_key IS NULL AND is_builtin = 0',
            ['n' => $name, 'd' => $description, 'id' => $roleId],
        ) === 1;
    }

    public function addPermission(int $roleId, Permission $permission): void
    {
        $this->db->execute('INSERT INTO role_permissions (role_id, permission) VALUES (:r, :p)', ['r' => $roleId, 'p' => $permission->value]);
    }

    public function removePermission(int $roleId, Permission $permission): void
    {
        $this->db->execute('DELETE FROM role_permissions WHERE role_id = :r AND permission = :p', ['r' => $roleId, 'p' => $permission->value]);
    }

    /** Löscht eine eigene Rolle (Rechte per CASCADE). @return bool ob eine Zeile gelöscht wurde */
    public function delete(int $roleId): bool
    {
        return $this->db->execute('DELETE FROM roles WHERE id = :id AND builtin_key IS NULL AND is_builtin = 0', ['id' => $roleId]) === 1;
    }

    public function countAssignments(int $roleId): int
    {
        return self::number($this->db->fetchOne(self::ASSIGNMENTS_SQL, ['r' => $roleId]));
    }

    /** Zuweisungen, die nicht ausdrücklich „Alle Kategorien“ ohne Kategoriezeilen sind (dieselbe Lesart wie grantsFor()). */
    public function countRestrictedAssignments(int $roleId): int
    {
        return self::number($this->db->fetchOne(self::RESTRICTED_SQL, ['r' => $roleId]));
    }

    public function countActiveUsers(int $roleId): int
    {
        return self::number($this->db->fetchOne(self::ACTIVE_USERS_SQL, ['r' => $roleId]));
    }

    /**
     * Alle Benutzer mit einer Zuweisung dieser Rolle, auch inaktive und mit Pflichtwechsel (gelöschte haben keine).
     *
     * @return list<int>
     */
    public function holders(int $roleId): array
    {
        $ids = [];
        foreach ($this->db->fetchAll(self::HOLDERS_SQL, ['r' => $roleId]) as $row) {
            if (isset($row['user_id']) && is_int($row['user_id'])) {
                $ids[] = $row['user_id'];
            }
        }

        return $ids;
    }

    /** Hat der Benutzer die Admin-Rolle ausdrücklich für alle Kategorien (ADR 0006 F5)? */
    public function holdsUnrestrictedAdminRole(int $userId): bool
    {
        return $this->db->fetchOne(self::HOLDS_FULL_ADMIN_SQL, ['u' => $userId]) !== null;
    }

    /** Gibt es den Namen schon (ohne Rücksicht auf Schreibung), außer bei der Rolle $exceptId? */
    public function nameTaken(string $name, ?int $exceptId = null): bool
    {
        return $this->db->fetchOne(self::NAME_TAKEN_SQL, ['n' => $name, 'id' => $exceptId]) !== null;
    }

    public function count(): int
    {
        return self::number($this->db->fetchOne('SELECT COUNT(*) AS n FROM roles'));
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function number(?array $row): int
    {
        // Unlesbar → so tun, als gäbe es sehr viele (fail-closed: Löschen, gefährliche Rechte und Anlegen werden abgelehnt).
        return $row !== null && isset($row['n']) && is_int($row['n']) ? $row['n'] : PHP_INT_MAX;
    }
}
