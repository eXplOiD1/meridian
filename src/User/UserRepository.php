<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Database\Connection;
use Meridian\Security\PasswordHasher;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;

final class UserRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $hasher,
    ) {
    }

    public function create(string $username, string $displayName, #[\SensitiveParameter] string $password, string $role): int
    {
        if (preg_match('/^[a-z0-9._-]{2,64}$/i', $username) !== 1) {
            throw new \InvalidArgumentException('Benutzername: 2 bis 64 Zeichen, nur Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich.');
        }

        $roleRow = $this->db->fetchOne('SELECT id FROM roles WHERE name = :name', ['name' => $role]);
        if ($roleRow === null || !isset($roleRow['id']) || !is_int($roleRow['id'])) {
            throw new \InvalidArgumentException('Unbekannte Rolle.');
        }
        $roleId = $roleRow['id'];
        $hash = $this->hasher->hash($password);

        return $this->db->transaction(function (Connection $db) use ($username, $displayName, $hash, $roleId): int {
            $db->execute(
                'INSERT INTO users (username, display_name, password_hash, created_at) VALUES (:u, :d, :h, :c)',
                ['u' => $username, 'd' => $displayName, 'h' => $hash, 'c' => gmdate('c')],
            );
            $userId = $db->lastInsertId();
            $db->execute(
                'INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)',
                ['u' => $userId, 'r' => $roleId],
            );

            return $userId;
        });
    }

    public function findByUsername(string $username): ?UserAccount
    {
        return $this->account($this->db->fetchOne(
            'SELECT id, username, display_name, password_hash, is_active FROM users WHERE username = :u',
            ['u' => $username],
        ));
    }

    public function findById(int $id): ?UserAccount
    {
        return $this->account($this->db->fetchOne(
            'SELECT id, username, display_name, password_hash, is_active FROM users WHERE id = :id',
            ['id' => $id],
        ));
    }

    public function updatePasswordHash(int $userId, #[\SensitiveParameter] string $hash): void
    {
        $this->db->execute('UPDATE users SET password_hash = :h WHERE id = :id', ['h' => $hash, 'id' => $userId]);
    }

    public function markLogin(int $userId, \DateTimeImmutable $at): void
    {
        $this->db->execute('UPDATE users SET last_login_at = :at WHERE id = :id', ['at' => $at->format('c'), 'id' => $userId]);
    }

    public function hasUsers(): bool
    {
        return $this->db->fetchOne('SELECT id FROM users LIMIT 1') !== null;
    }

    /**
     * Lädt die Rollen eines Benutzers inklusive Kategorie-Beschränkung.
     *
     * @return list<RoleGrant>
     */
    public function grantsFor(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ur.id AS user_role_id, r.name AS role, rp.permission
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN users u ON u.id = ur.user_id
              WHERE ur.user_id = :id AND u.is_active = 1',
            ['id' => $userId],
        );

        /** @var array<int, array{role: string, permissions: list<Permission>}> $byGrant */
        $byGrant = [];
        foreach ($rows as $row) {
            $grantId = $row['user_role_id'] ?? null;
            $roleName = $row['role'] ?? null;
            $permission = isset($row['permission']) && is_string($row['permission']) ? Permission::tryFrom($row['permission']) : null;
            if (!is_int($grantId) || !is_string($roleName) || $permission === null) {
                continue;
            }
            $byGrant[$grantId] ??= ['role' => $roleName, 'permissions' => []];
            $byGrant[$grantId]['permissions'][] = $permission;
        }

        $grants = [];
        foreach ($byGrant as $grantId => $grant) {
            $categoryRows = $this->db->fetchAll(
                'SELECT c.name FROM user_role_categories urc JOIN categories c ON c.id = urc.category_id WHERE urc.user_role_id = :id',
                ['id' => $grantId],
            );
            $categories = [];
            foreach ($categoryRows as $categoryRow) {
                if (isset($categoryRow['name']) && is_string($categoryRow['name'])) {
                    $categories[] = $categoryRow['name'];
                }
            }
            $grants[] = new RoleGrant($grant['role'], $grant['permissions'], $categories === [] ? null : $categories);
        }

        return $grants;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function account(?array $row): ?UserAccount
    {
        if ($row === null || !isset($row['id'], $row['username'], $row['display_name'], $row['password_hash'], $row['is_active'])
            || !is_int($row['id']) || !is_string($row['username']) || !is_string($row['display_name'])
            || !is_string($row['password_hash']) || !is_int($row['is_active'])) {
            return null;
        }

        return new UserAccount($row['id'], $row['username'], $row['display_name'], $row['password_hash'], $row['is_active'] === 1);
    }
}
