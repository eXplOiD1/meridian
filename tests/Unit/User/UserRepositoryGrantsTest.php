<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\User;

use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Security\AccessControl;
use Meridian\Security\PasswordHasher;
use Meridian\Security\Permission;
use Meridian\User\UserRepository;
use PHPUnit\Framework\TestCase;

final class UserRepositoryGrantsTest extends TestCase
{
    private ?Connection $dbInstance = null;

    private function db(): Connection
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = Connection::inMemory();
            (new Migrator($this->dbInstance, dirname(__DIR__, 3) . '/migrations'))->migrate();
            $this->dbInstance->execute("INSERT INTO categories (id, name) VALUES (1, 'A'), (2, 'B')");
        }

        return $this->dbInstance;
    }

    private function users(): UserRepository
    {
        return new UserRepository($this->db(), new PasswordHasher());
    }

    private function grantId(int $userId): int
    {
        $row = $this->db()->fetchOne('SELECT id FROM user_roles WHERE user_id = :u', ['u' => $userId]);
        $id = is_array($row) ? ($row['id'] ?? null) : null;
        self::assertIsInt($id);

        return $id;
    }

    private function canEditB(int $userId): bool
    {
        return (new AccessControl())->can($this->users()->grantsFor($userId), Permission::EditHttpJobs, 'B');
    }

    public function testCreatedAssignmentsAreExplicitlyUnrestricted(): void
    {
        $id = $this->users()->create('alex', 'Alex', 'TEST-PASSWORD-do-not-use-1', 'Operator');

        self::assertTrue($this->canEditB($id));
        self::assertSame(1, $this->db()->fetchOne('SELECT all_categories FROM user_roles')['all_categories'] ?? null);
    }

    public function testRestrictedAssignmentSeesOnlyItsCategories(): void
    {
        $id = $this->users()->create('begrenzt', 'B', 'TEST-PASSWORD-do-not-use-1', 'Operator');
        $grant = $this->grantId($id);
        $this->db()->execute('UPDATE user_roles SET all_categories = 0 WHERE id = :g', ['g' => $grant]);
        $this->db()->execute('INSERT INTO user_role_categories (user_role_id, category_id) VALUES (:g, 1)', ['g' => $grant]);

        self::assertSame(['A'], $this->users()->grantsFor($id)[0]->categories);
        self::assertFalse($this->canEditB($id));
    }

    public function testContradictoryStateFlagAndRowsIsTreatedAsRestricted(): void
    {
        // Simulate legacy corruption; current writes are protected by the database trigger.
        $this->db()->execute('DROP TRIGGER user_role_categories_not_with_all');
        $id = $this->users()->create('widerspruch', 'W', 'TEST-PASSWORD-do-not-use-1', 'Operator');
        $grant = $this->grantId($id);
        // Flag „alle“ gesetzt UND eine Kategoriezeile: im Zweifel die engere Lesart.
        $this->db()->execute('INSERT INTO user_role_categories (user_role_id, category_id) VALUES (:g, 1)', ['g' => $grant]);

        self::assertSame(['A'], $this->users()->grantsFor($id)[0]->categories);
        self::assertFalse($this->canEditB($id));
    }

    public function testNoFlagAndNoRowsAllowsNothing(): void
    {
        $id = $this->users()->create('leer', 'L', 'TEST-PASSWORD-do-not-use-1', 'Operator');
        $this->db()->execute('UPDATE user_roles SET all_categories = 0 WHERE id = :g', ['g' => $this->grantId($id)]);

        self::assertSame([], $this->users()->grantsFor($id)[0]->categories);
        self::assertFalse($this->canEditB($id));
    }
}
