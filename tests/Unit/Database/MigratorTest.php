<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Database;

use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Security\PasswordHasher;
use Meridian\Security\Permission;
use Meridian\User\UserRepository;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testMigrationsRunOnceAndCreateDefaultRoles(): void
    {
        $db = Connection::inMemory();
        $migrator = new Migrator($db, dirname(__DIR__, 3) . '/Meridian/migrations');

        self::assertSame(['0001_init.sql'], $migrator->migrate());
        self::assertSame([], $migrator->migrate());

        $roles = array_column($db->fetchAll('SELECT name FROM roles ORDER BY id'), 'name');
        self::assertSame(['Admin', 'Operator', 'Beobachter'], $roles);
    }

    public function testUserGetsRolePermissions(): void
    {
        $db = Connection::inMemory();
        (new Migrator($db, dirname(__DIR__, 3) . '/Meridian/migrations'))->migrate();
        $users = new UserRepository($db, new PasswordHasher());

        $id = $users->create('jana', 'Jana', 'ein-langes-passwort', 'Operator');
        $grants = $users->grantsFor($id);

        self::assertCount(1, $grants);
        self::assertContains(Permission::RunJobs, $grants[0]->permissions);
        self::assertNotContains(Permission::EditShellJobs, $grants[0]->permissions);
    }

    public function testPasswordIsNotStoredInPlainText(): void
    {
        $db = Connection::inMemory();
        (new Migrator($db, dirname(__DIR__, 3) . '/Meridian/migrations'))->migrate();
        (new UserRepository($db, new PasswordHasher()))->create('alex', 'Alex', 'ein-langes-passwort', 'Admin');

        $row = $db->fetchOne('SELECT password_hash FROM users WHERE username = :u', ['u' => 'alex']);
        self::assertNotNull($row);
        self::assertIsString($row['password_hash']);
        self::assertStringNotContainsString('ein-langes-passwort', $row['password_hash']);
    }
}
