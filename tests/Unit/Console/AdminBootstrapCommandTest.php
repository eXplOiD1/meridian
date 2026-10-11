<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Console;

use Meridian\Auth\AuditLog;
use Meridian\Console\AdminBootstrapCommand;
use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Security\PasswordHasher;
use Meridian\Security\SecretMasker;
use Meridian\Tests\Support\MutableClock;
use Meridian\User\UserRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AdminBootstrapCommandTest extends TestCase
{
    private ?Connection $dbInstance = null;

    private function db(): Connection
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = Connection::inMemory();
            (new Migrator($this->dbInstance, dirname(__DIR__, 3) . '/migrations'))->migrate();
        }

        return $this->dbInstance;
    }

    /**
     * @param array<string, string> $env
     */
    private function bootstrap(array $env): CommandTester
    {
        $hasher = new PasswordHasher();
        $command = new AdminBootstrapCommand(
            new UserRepository($this->db(), $hasher),
            $env,
            new AuditLog($this->db(), new MutableClock(), new SecretMasker()),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private function shownPassword(CommandTester $tester): string
    {
        $found = preg_match('/Zufälliges Passwort[^:]*: (\S+)/u', $tester->getDisplay(true), $match);
        if ($found !== 1 || !isset($match[1])) {
            self::fail('Kein Zufallspasswort in der Ausgabe.');
        }

        return $match[1];
    }

    private function userCount(): int
    {
        $n = self::dig($this->db()->fetchOne('SELECT COUNT(*) AS n FROM users'), 'n');
        self::assertIsInt($n);

        return $n;
    }

    private static function dig(mixed $data, string $key): mixed
    {
        return is_array($data) ? ($data[$key] ?? null) : null;
    }

    public function testCreatesTheAdminFromTheEnvironmentAndNeverPrintsThePassword(): void
    {
        $tester = $this->bootstrap(['MERIDIAN_ADMIN_USER' => 'alex', 'MERIDIAN_ADMIN_PASSWORD' => 'TEST-PASSWORD-do-not-use-1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Admin "alex" angelegt', $tester->getDisplay(true));
        self::assertStringNotContainsString('TEST-PASSWORD', $tester->getDisplay(true));
        self::assertSame(1, $this->userCount());

        $role = self::dig($this->db()->fetchOne('SELECT r.name AS name FROM user_roles ur JOIN roles r ON r.id = ur.role_id'), 'name');
        self::assertSame('Admin', $role);
        $hash = self::dig($this->db()->fetchOne('SELECT password_hash FROM users'), 'password_hash');
        self::assertIsString($hash);
        self::assertTrue((new PasswordHasher())->verify('TEST-PASSWORD-do-not-use-1', $hash));
    }

    public function testWithoutAnyVariablesAnAdminWithARandomPasswordIsCreatedAndShownOnce(): void
    {
        $tester = $this->bootstrap([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $password = $this->shownPassword($tester);
        self::assertGreaterThanOrEqual(16, strlen($password));

        $row = $this->db()->fetchOne('SELECT username, password_hash FROM users');
        self::assertSame('admin', self::dig($row, 'username'));
        $hash = self::dig($row, 'password_hash');
        self::assertIsString($hash);
        self::assertTrue((new PasswordHasher())->verify($password, $hash));

        $auditDump = json_encode($this->db()->fetchAll('SELECT * FROM audit_log'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($password, $auditDump);
    }

    public function testRandomPasswordsDiffer(): void
    {
        $first = $this->bootstrap([]);
        $a = $this->shownPassword($first);

        $this->dbInstance = null;
        $b = $this->shownPassword($this->bootstrap([]));

        self::assertNotSame($a, $b);
    }

    public function testDoesNothingOnceUsersExist(): void
    {
        $this->bootstrap(['MERIDIAN_ADMIN_USER' => 'alex', 'MERIDIAN_ADMIN_PASSWORD' => 'TEST-PASSWORD-do-not-use-1']);
        $before = self::dig($this->db()->fetchOne('SELECT password_hash FROM users'), 'password_hash');

        $tester = $this->bootstrap(['MERIDIAN_ADMIN_USER' => 'anderer', 'MERIDIAN_ADMIN_PASSWORD' => 'TEST-PASSWORD-do-not-use-2']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ignoriert', $tester->getDisplay(true));
        self::assertSame(1, $this->userCount());
        self::assertSame($before, self::dig($this->db()->fetchOne('SELECT password_hash FROM users'), 'password_hash'), 'ein bestehendes Passwort wird nie überschrieben');
    }

    public function testReadsThePasswordFromAFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'meridian-test-');
        self::assertIsString($file);
        file_put_contents($file, "TEST-PASSWORD-do-not-use-1\n");

        try {
            $tester = $this->bootstrap(['MERIDIAN_ADMIN_USER' => 'alex', 'MERIDIAN_ADMIN_PASSWORD_FILE' => $file]);
        } finally {
            unlink($file);
        }

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $hash = self::dig($this->db()->fetchOne('SELECT password_hash FROM users'), 'password_hash');
        self::assertIsString($hash);
        self::assertTrue((new PasswordHasher())->verify('TEST-PASSWORD-do-not-use-1', $hash));
    }

    public function testFailsClearlyWithAnUnreadablePasswordFileOrATooShortPassword(): void
    {
        $missing = $this->bootstrap(['MERIDIAN_ADMIN_USER' => 'alex', 'MERIDIAN_ADMIN_PASSWORD_FILE' => '/nicht/vorhanden']);
        self::assertSame(Command::FAILURE, $missing->getStatusCode());
        self::assertSame(0, $this->userCount());

        $short = $this->bootstrap(['MERIDIAN_ADMIN_USER' => 'alex', 'MERIDIAN_ADMIN_PASSWORD' => '1234567']);
        self::assertSame(Command::INVALID, $short->getStatusCode());
        self::assertStringContainsString('mindestens 8 Zeichen', $short->getDisplay(true));
        self::assertSame(0, $this->userCount());

        $badName = $this->bootstrap(['MERIDIAN_ADMIN_USER' => 'a b', 'MERIDIAN_ADMIN_PASSWORD' => 'TEST-PASSWORD-do-not-use-1']);
        self::assertSame(Command::INVALID, $badName->getStatusCode());
        self::assertSame(0, $this->userCount());
    }

    public function testCreationIsWrittenToTheAuditLog(): void
    {
        $this->bootstrap(['MERIDIAN_ADMIN_USER' => 'alex', 'MERIDIAN_ADMIN_PASSWORD' => 'TEST-PASSWORD-do-not-use-1']);

        $row = $this->db()->fetchOne('SELECT user_id, action, target FROM audit_log');
        self::assertSame('user.created', self::dig($row, 'action'));
        self::assertNull(self::dig($row, 'user_id'), 'Befehlszeile: kein angemeldeter Benutzer');
        $target = self::dig($row, 'target');
        self::assertIsString($target);
        self::assertStringContainsString('alex', $target);
        self::assertStringNotContainsString('TEST-PASSWORD', json_encode($row, JSON_THROW_ON_ERROR));
    }
}
