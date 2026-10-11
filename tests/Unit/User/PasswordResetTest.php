<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\User;

use Meridian\Auth\AuditLog;
use Meridian\Auth\LoginThrottle;
use Meridian\Auth\SessionManager;
use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Security\PasswordHasher;
use Meridian\Security\SecretMasker;
use Meridian\Tests\Support\MutableClock;
use Meridian\User\PasswordReset;
use Meridian\User\UserRepository;
use PHPUnit\Framework\TestCase;

final class PasswordResetTest extends TestCase
{
    private const OLD = 'TEST-PASSWORD-do-not-use-1';
    private const NEW = 'TEST-PASSWORD-do-not-use-2';

    private ?Connection $dbInstance = null;

    private function db(): Connection
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = Connection::inMemory();
            (new Migrator($this->dbInstance, dirname(__DIR__, 3) . '/migrations'))->migrate();
        }

        return $this->dbInstance;
    }

    private function reset(): PasswordReset
    {
        $clock = new MutableClock();
        $hasher = new PasswordHasher();

        return new PasswordReset(
            new UserRepository($this->db(), $hasher),
            $hasher,
            new SessionManager($this->db(), $clock),
            new LoginThrottle($this->db(), $clock),
            new AuditLog($this->db(), $clock, new SecretMasker()),
            $clock,
        );
    }

    public function testSetsTheNewPasswordEndsSessionsUnlocksAndAudits(): void
    {
        $hasher = new PasswordHasher();
        $users = new UserRepository($this->db(), $hasher);
        $id = $users->create('alex', 'Alex', self::OLD, 'Admin');
        $clock = new MutableClock();
        (new SessionManager($this->db(), $clock))->start($id);
        $throttle = new LoginThrottle($this->db(), $clock);
        for ($i = 0; $i < 5; ++$i) {
            $throttle->recordFailure('alex', '10.0.0.' . $i);
        }
        self::assertGreaterThan(0, $throttle->lockedFor('alex', '10.9.9.9'));

        self::assertTrue($this->reset()->reset('alex', self::NEW));

        $row = $this->db()->fetchOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $id]);
        $hash = is_array($row) ? ($row['password_hash'] ?? null) : null;
        self::assertIsString($hash);
        self::assertTrue($hasher->verify(self::NEW, $hash));
        self::assertFalse($hasher->verify(self::OLD, $hash));
        self::assertNull($this->db()->fetchOne('SELECT 1 AS x FROM sessions'), 'alle Sitzungen beendet');
        self::assertSame(0, $throttle->lockedFor('alex', '10.9.9.9'));

        $audit = $this->db()->fetchOne("SELECT user_id, action, target FROM audit_log WHERE action = 'user.password_reset'");
        self::assertSame(['user_id' => null, 'action' => 'user.password_reset', 'target' => 'alex'], $audit);
        self::assertStringNotContainsString(self::NEW, json_encode($this->db()->fetchAll('SELECT * FROM audit_log'), JSON_THROW_ON_ERROR));
    }

    public function testUnknownUserChangesNothing(): void
    {
        self::assertFalse($this->reset()->reset('niemand', self::NEW));
        self::assertNull($this->db()->fetchOne('SELECT 1 AS x FROM audit_log'));
    }

    public function testTooShortPasswordChangesNothing(): void
    {
        $hasher = new PasswordHasher();
        $users = new UserRepository($this->db(), $hasher);
        $users->create('alex', 'Alex', self::OLD, 'Admin');
        $before = $this->db()->fetchOne('SELECT password_hash FROM users');

        try {
            $this->reset()->reset('alex', '1234567');
            self::fail('Zu kurzes Passwort hätte abgelehnt werden müssen.');
        } catch (\InvalidArgumentException) {
            // erwartet
        }

        self::assertSame($before, $this->db()->fetchOne('SELECT password_hash FROM users'));
        self::assertNull($this->db()->fetchOne('SELECT 1 AS x FROM audit_log'));
    }

    public function testTwoFactorStaysUntouched(): void
    {
        $hasher = new PasswordHasher();
        $users = new UserRepository($this->db(), $hasher);
        $id = $users->create('alex', 'Alex', self::OLD, 'Admin');
        $this->db()->execute('UPDATE users SET totp_enabled = 1, totp_secret_enc = :s WHERE id = :id', ['s' => 'v1:platzhalter', 'id' => $id]);

        $this->reset()->reset('alex', self::NEW);

        $row = $this->db()->fetchOne('SELECT totp_enabled, totp_secret_enc FROM users');
        self::assertSame(['totp_enabled' => 1, 'totp_secret_enc' => 'v1:platzhalter'], $row);
    }
}
