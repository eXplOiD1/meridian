<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Console;

use Meridian\Auth\AuditLog;
use Meridian\Auth\LoginThrottle;
use Meridian\Console\KeyGenerateCommand;
use Meridian\Console\UnlockCommand;
use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Security\KeyLoader;
use Meridian\Security\SecretMasker;
use Meridian\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class KeyAndUnlockCommandTest extends TestCase
{
    public function testKeyGenerateCreatesAPrivateFileAndNeverOverwrites(): void
    {
        $dir = sys_get_temp_dir() . '/meridian-key-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700);
        $file = $dir . '/master.key';

        try {
            $tester = new CommandTester(new KeyGenerateCommand());
            $tester->execute(['path' => $file]);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());
            self::assertSame(0o600, fileperms($file) & 0o777);
            self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen(KeyLoader::fromFile($file)));
            self::assertStringNotContainsString(trim((string) file_get_contents($file)), $tester->getDisplay(true), 'Der Schlüssel wird nie ausgegeben.');

            $before = file_get_contents($file);
            $again = new CommandTester(new KeyGenerateCommand());
            $again->execute(['path' => $file]);
            self::assertSame(Command::FAILURE, $again->getStatusCode());
            self::assertSame($before, file_get_contents($file));
        } finally {
            $files = glob($dir . '/*');
            foreach ($files === false ? [] : $files as $f) {
                unlink($f);
            }
            rmdir($dir);
        }
    }

    public function testKeyGenerateDoesNotFollowADanglingSymlinkOntoAnExistingTarget(): void
    {
        $dir = sys_get_temp_dir() . '/meridian-key-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700);
        $victim = $dir . '/ziel.txt';
        file_put_contents($victim, 'unveraendert');
        $link = $dir . '/master.key';
        symlink($victim, $link);

        try {
            $tester = new CommandTester(new KeyGenerateCommand());
            $tester->execute(['path' => $link]);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertSame('unveraendert', file_get_contents($victim));
        } finally {
            unlink($link);
            unlink($victim);
            rmdir($dir);
        }
    }

    public function testUnlockCommandFreesAUserOrAnIpAndWritesAuditEntries(): void
    {
        $db = Connection::inMemory();
        (new Migrator($db, dirname(__DIR__, 3) . '/migrations'))->migrate();
        $clock = new MutableClock();
        $throttle = new LoginThrottle($db, $clock);
        $command = new UnlockCommand($throttle, new AuditLog($db, $clock, new SecretMasker()));
        for ($i = 0; $i < 50; ++$i) {
            $throttle->recordFailure($i < 5 ? 'alex' : 'name' . $i, '10.0.0.1');
        }
        self::assertGreaterThan(0, $throttle->lockedFor('alex', '10.9.9.9'));
        self::assertGreaterThan(0, $throttle->lockedFor('niemand', '10.0.0.1'));

        $byName = new CommandTester($command);
        $byName->execute(['target' => 'alex']);
        $byIp = new CommandTester($command);
        $byIp->execute(['target' => '10.0.0.1']);

        self::assertSame(Command::SUCCESS, $byName->getStatusCode());
        self::assertSame(Command::SUCCESS, $byIp->getStatusCode());
        self::assertSame(0, $throttle->lockedFor('alex', '10.9.9.9'));
        self::assertSame(0, $throttle->lockedFor('niemand', '10.0.0.1'));
        $targets = array_column($db->fetchAll("SELECT target FROM audit_log WHERE action = 'auth.unlocked' ORDER BY id"), 'target');
        self::assertSame(['alex', 'ip:10.0.0.1'], $targets);
    }

    public function testUnlockCommandRejectsAnythingElse(): void
    {
        $db = Connection::inMemory();
        (new Migrator($db, dirname(__DIR__, 3) . '/migrations'))->migrate();
        $clock = new MutableClock();
        $command = new UnlockCommand(new LoginThrottle($db, $clock), new AuditLog($db, $clock, new SecretMasker()));

        foreach (['a b', "x'; DROP TABLE users;--", 'a', str_repeat('x', 65)] as $target) {
            $tester = new CommandTester($command);
            $tester->execute(['target' => $target]);
            self::assertSame(Command::INVALID, $tester->getStatusCode(), $target);
        }
        self::assertNull($db->fetchOne('SELECT 1 AS x FROM audit_log'));
    }
}
