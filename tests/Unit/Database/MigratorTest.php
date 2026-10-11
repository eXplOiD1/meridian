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
        $migrator = new Migrator($db, dirname(__DIR__, 3) . '/migrations');

        self::assertSame(['0001_init.sql','0002_auth.sql','0003_totp.sql','0004_all_categories.sql','0005_scheduler.sql','0006_run_heartbeat.sql','0007_http_jobs.sql','0008_display_host.sql','0009_users_roles.sql','0010_workers_and_live_log.sql','0011_shell_jobs.sql','0012_reveal_for_edit.sql','0013_custom_roles.sql','0014_job_expires_at.sql','0015_notifications.sql','0016_notification_kinds_public_url.sql','0017_run_retention.sql','0018_api_token_read_only.sql','0019_monitoring.sql','0020_monitors_status_pages.sql','0021_min_interval_setting.sql','0022_session_days_setting.sql','0023_user_language.sql','0024_user_theme.sql','0025_active_monitors.sql','0026_notification_language.sql'], $migrator->migrate());
        self::assertSame([], $migrator->migrate());

        $roles = array_column($db->fetchAll('SELECT name FROM roles ORDER BY id'), 'name');
        self::assertSame(['Admin', 'Operator', 'Beobachter'], $roles);
    }

    public function testUserGetsRolePermissions(): void
    {
        $db = Connection::inMemory();
        (new Migrator($db, dirname(__DIR__, 3) . '/migrations'))->migrate();
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
        (new Migrator($db, dirname(__DIR__, 3) . '/migrations'))->migrate();
        (new UserRepository($db, new PasswordHasher()))->create('alex', 'Alex', 'ein-langes-passwort', 'Admin');

        $row = $db->fetchOne('SELECT password_hash FROM users WHERE username = :u', ['u' => 'alex']);
        self::assertNotNull($row);
        self::assertIsString($row['password_hash']);
        self::assertStringNotContainsString('ein-langes-passwort', $row['password_hash']);
    }
}
