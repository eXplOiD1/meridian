<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Database;

use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Security\AccessControl;
use Meridian\Security\PasswordHasher;
use Meridian\Security\Permission;
use Meridian\Tests\Support\SchedulerHarness;
use Meridian\User\UserRepository;
use PHPUnit\Framework\TestCase;

/**
 * Migration 0007 (Phase 3): neue Rechte nur für Admin, Tabellen settings und http_internal_targets mit ihren
 * Prüfungen, Kaskade gelöschter Kategorien verengt statt zu erweitern.
 */
final class HttpJobsMigrationTest extends TestCase
{
    private const NEW_PERMISSIONS = ['network.internal_targets', 'settings.manage'];

    private string $file = '';
    private string $dir = '';

    protected function setUp(): void
    {
        $this->file = SchedulerHarness::tempDatabase();
        $this->dir = sys_get_temp_dir() . '/meridian-mig7-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        SchedulerHarness::removeDatabase($this->file);
        $files = glob($this->dir . '/*');
        foreach ($files === false ? [] : $files as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    public function testFreshInstallGivesTheNewPermissionsOnlyToAdmin(): void
    {
        $db = $this->migrated();

        self::assertSame(self::NEW_PERMISSIONS, $this->newPermissionsOf($db, 'Admin'));
        self::assertSame([], $this->newPermissionsOf($db, 'Operator'));
        self::assertSame([], $this->newPermissionsOf($db, 'Beobachter'));
    }

    public function testExistingInstallationOnlyAdminGetsThemAndNothingIsDuplicated(): void
    {
        $this->copyMigrations(static fn (string $name): bool => $name < '0007');
        $old = Connection::open($this->file);
        (new Migrator($old, $this->dir))->migrate();
        // Bestand: eigene Rolle, Admin hat eines der Rechte schon (z. B. von Hand), Operator-Rechte vom Admin geändert.
        $old->execute("INSERT INTO roles (id, name) VALUES (9, 'Technik')");
        $old->execute("INSERT INTO role_permissions (role_id, permission) VALUES (9, 'jobs.view'), (1, 'settings.manage')");
        $old->execute("DELETE FROM role_permissions WHERE role_id = 2 AND permission = 'jobs.edit_http'");
        $before = $this->allPermissions($old);

        $this->copyMigrations(static fn (string $name): bool => $name === '0007_http_jobs.sql');
        $db = Connection::open($this->file);
        self::assertSame(['0007_http_jobs.sql'], (new Migrator($db, $this->dir))->migrate());

        $fresh = Connection::open($this->file);
        self::assertSame(self::NEW_PERMISSIONS, $this->newPermissionsOf($fresh, 'Admin'));
        self::assertSame([], $this->newPermissionsOf($fresh, 'Operator'));
        self::assertSame([], $this->newPermissionsOf($fresh, 'Beobachter'));
        self::assertSame([], $this->newPermissionsOf($fresh, 'Technik'));
        $after = $this->allPermissions($fresh);
        self::assertSame(
            array_values(array_diff($after, $before)),
            ['1:network.internal_targets'],
            'nur das fehlende Recht ergänzt, Änderungen des Admins bleiben',
        );
        self::assertSame([], array_values(array_diff($before, $after)));
    }

    public function testRunningTheGrantTwiceChangesNothing(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/migrations/0007_http_jobs.sql');
        self::assertIsString($sql);
        $grant = strstr($sql, 'CREATE TABLE settings', true);
        self::assertIsString($grant);
        self::assertStringContainsString('INSERT INTO role_permissions', $grant);
        $this->copyMigrations(static fn (): bool => true);
        file_put_contents($this->dir . '/0099_grant_again.sql', $grant);

        $db = Connection::open($this->file);
        $applied = (new Migrator($db, $this->dir))->migrate();
        self::assertContains('0099_grant_again.sql', $applied);
        self::assertSame(self::NEW_PERMISSIONS, $this->newPermissionsOf($db, 'Admin'));
        self::assertSame([], (new Migrator($db, $this->dir))->migrate());
    }

    public function testRestrictedAdminAssignmentNeverGetsTheNewPermissions(): void
    {
        $db = $this->migrated();
        $db->execute("INSERT INTO categories (id, name) VALUES (1, 'A')");
        $users = new UserRepository($db, new PasswordHasher());
        $full = $users->create('voll', 'Voll', 'TEST-PASSWORD-do-not-use-1', 'Admin');
        $restricted = $users->create('teil', 'Teil', 'TEST-PASSWORD-do-not-use-1', 'Admin');
        $db->execute('UPDATE user_roles SET all_categories = 0 WHERE user_id = :u', ['u' => $restricted]);
        $db->execute('INSERT INTO user_role_categories (user_role_id, category_id) SELECT id, 1 FROM user_roles WHERE user_id = :u', ['u' => $restricted]);

        $acl = new AccessControl();
        foreach ([Permission::ManageInternalTargets, Permission::ManageSettings] as $permission) {
            self::assertTrue($acl->can($users->grantsFor($full), $permission));
            self::assertFalse($acl->can($users->grantsFor($restricted), $permission), $permission->value . ' ohne Kategorie');
            self::assertFalse($acl->can($users->grantsFor($restricted), $permission, 'A'), $permission->value . ' in der eigenen Kategorie');
            self::assertTrue($acl->scope($users->grantsFor($restricted), $permission)->isEmpty());
        }
        self::assertTrue($acl->can($users->grantsFor($restricted), Permission::EditHttpJobs, 'A'));
    }

    public function testSettingsAcceptOnlyKnownKeysAndValidJson(): void
    {
        $db = $this->migrated();
        $db->execute("INSERT INTO users (id, username, display_name, password_hash, created_at) VALUES (5, 'alex', 'Alex', 'x', 't')");
        $db->execute("INSERT INTO settings (key, value_json, updated_by, updated_at) VALUES ('http.max_timeout_seconds', '600', 5, 't'), ('http.response_storage', '\"never\"', NULL, 't'), ('http.display_path', '\"hidden\"', 5, 't')");

        $this->assertRejected([
            static fn () => $db->execute("INSERT INTO settings (key, value_json, updated_at) VALUES ('http.erfunden', '1', 't')"),
            static fn () => $db->execute("INSERT INTO settings (key, value_json, updated_at) VALUES ('HTTP.MAX_TIMEOUT_SECONDS', '1', 't')"),
            static fn () => $db->execute("UPDATE settings SET value_json = 'kein json' WHERE key = 'http.max_timeout_seconds'"),
            static fn () => $db->execute("UPDATE settings SET value_json = '{\"a\":' WHERE key = 'http.display_path'"),
            static fn () => $db->execute("INSERT INTO settings (key, value_json, updated_at) VALUES ('http.max_timeout_seconds', '300', 't')"),
            static fn () => $db->execute("INSERT INTO settings (key, value_json) VALUES ('http.response_storage', '\"on\"')"),
        ]);

        $db->execute('DELETE FROM users WHERE id = 5');
        self::assertSame(
            [['key' => 'http.display_path', 'updated_by' => null], ['key' => 'http.max_timeout_seconds', 'updated_by' => null], ['key' => 'http.response_storage', 'updated_by' => null]],
            $db->fetchAll('SELECT key, updated_by FROM settings ORDER BY key'),
            'gelöschter Benutzer löscht keine Einstellung',
        );
    }

    public function testDeletedCategoryDeletesItsReleasesAndNeverWidensThem(): void
    {
        $db = $this->migrated();
        $db->execute("INSERT INTO categories (id, name) VALUES (1, 'A'), (2, 'B')");
        $db->execute(
            "INSERT INTO http_internal_targets (kind, value, port, category_id, created_at) VALUES
                ('cidr', '192.168.1.0/24', 0, 1, 't'),
                ('host', 'nextcloud', 443, 1, 't'),
                ('cidr', '192.168.1.0/24', 0, 2, 't'),
                ('cidr', '10.0.0.0/8', 8080, NULL, 't')",
        );

        $db->execute('DELETE FROM categories WHERE id = 1');

        $fresh = Connection::open($this->file);
        self::assertSame(
            [
                ['kind' => 'cidr', 'value' => '192.168.1.0/24', 'port' => 0, 'category_id' => 2],
                ['kind' => 'cidr', 'value' => '10.0.0.0/8', 'port' => 8080, 'category_id' => null],
            ],
            $fresh->fetchAll('SELECT kind, value, port, category_id FROM http_internal_targets ORDER BY id'),
        );
        self::assertSame(0, $fresh->fetchOne('SELECT COUNT(*) AS n FROM http_internal_targets WHERE category_id IS NULL AND value = :v', ['v' => '192.168.1.0/24'])['n'] ?? null);
    }

    public function testReleaseUniquenessAndChecks(): void
    {
        $db = $this->migrated();
        $db->execute("INSERT INTO categories (id, name) VALUES (1, 'A')");
        $db->execute("INSERT INTO http_internal_targets (kind, value, port, category_id, created_at) VALUES ('cidr', '192.168.1.0/24', 0, NULL, 't')");
        // Gleicher Wert in einer Kategorie und mit anderem Port ist eine andere Freigabe.
        $db->execute("INSERT INTO http_internal_targets (kind, value, port, category_id, created_at) VALUES ('cidr', '192.168.1.0/24', 0, 1, 't')");
        $db->execute("INSERT INTO http_internal_targets (kind, value, port, category_id, created_at) VALUES ('cidr', '192.168.1.0/24', 80, NULL, 't')");

        $this->assertRejected([
            static fn () => $db->execute("INSERT INTO http_internal_targets (kind, value, port, category_id, created_at) VALUES ('cidr', '192.168.1.0/24', 0, NULL, 't')"),
            static fn () => $db->execute("INSERT INTO http_internal_targets (kind, value, port, category_id, created_at) VALUES ('cidr', '192.168.1.0/24', 0, 1, 't')"),
            static fn () => $db->execute("INSERT INTO http_internal_targets (kind, value, port, created_at) VALUES ('ip', '192.168.1.1', 0, 't')"),
            static fn () => $db->execute("INSERT INTO http_internal_targets (kind, value, port, created_at) VALUES ('host', '', 0, 't')"),
            static fn () => $db->execute("INSERT INTO http_internal_targets (kind, value, port, created_at) VALUES ('host', 'a', -1, 't')"),
            static fn () => $db->execute("INSERT INTO http_internal_targets (kind, value, port, created_at) VALUES ('host', 'a', 65536, 't')"),
            static fn () => $db->execute("INSERT INTO http_internal_targets (kind, value, port, category_id, created_at) VALUES ('host', 'a', 0, 99, 't')"),
            static fn () => $db->execute("INSERT INTO http_internal_targets (kind, value, port, note, created_at) VALUES ('host', 'a', 0, :note, 't')", ['note' => str_repeat('n', 201)]),
            static fn () => $db->execute("INSERT INTO http_internal_targets (kind, value, port, created_at) VALUES ('host', :v, 0, 't')", ['v' => str_repeat('a', 254)]),
            static fn () => $db->execute("INSERT INTO http_internal_targets (kind, value, port) VALUES ('host', 'a', 0)"),
        ]);

        $indexes = array_column($db->fetchAll("SELECT name FROM sqlite_master WHERE type = 'index' AND name IN ('jobs_category', 'runs_job_id', 'http_internal_targets_unique', 'http_internal_targets_category') ORDER BY name"), 'name');
        self::assertSame(['http_internal_targets_category', 'http_internal_targets_unique', 'jobs_category', 'runs_job_id'], $indexes);
        self::assertSame([], $db->fetchAll('PRAGMA foreign_key_check'));
    }

    private function migrated(): Connection
    {
        $db = Connection::open($this->file);
        (new Migrator($db, dirname(__DIR__, 3) . '/migrations'))->migrate();

        return $db;
    }

    /**
     * @param callable(string): bool $filter
     */
    private function copyMigrations(callable $filter): void
    {
        $all = glob(dirname(__DIR__, 3) . '/migrations/*.sql');
        foreach ($all === false ? [] : $all as $migration) {
            if ($filter(basename($migration))) {
                copy($migration, $this->dir . '/' . basename($migration));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function newPermissionsOf(Connection $db, string $role): array
    {
        $rows = $db->fetchAll(
            "SELECT rp.permission FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
              WHERE r.name = :role AND rp.permission IN ('network.internal_targets', 'settings.manage') ORDER BY rp.permission",
            ['role' => $role],
        );

        return array_values(array_map(static fn (array $r): string => is_string($r['permission'] ?? null) ? $r['permission'] : '', $rows));
    }

    /**
     * @return list<string>
     */
    private function allPermissions(Connection $db): array
    {
        $rows = $db->fetchAll("SELECT role_id || ':' || permission AS p FROM role_permissions ORDER BY role_id, permission");

        return array_values(array_map(static fn (array $r): string => is_string($r['p'] ?? null) ? $r['p'] : '', $rows));
    }

    /**
     * @param list<callable(): int> $attempts jede Anweisung muss an einer Prüfung der Datenbank scheitern
     */
    private function assertRejected(array $attempts): void
    {
        foreach ($attempts as $i => $attempt) {
            try {
                $attempt();
                self::fail('Prüfung fehlt bei Anweisung Nr. ' . $i);
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
