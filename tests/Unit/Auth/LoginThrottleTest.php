<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Auth;

use Meridian\Auth\LoginThrottle;
use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Tests\Support\MutableClock;
use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    private ?Connection $dbInstance = null;
    private ?MutableClock $clockInstance = null;
    private ?LoginThrottle $throttleInstance = null;

    private function db(): Connection
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = Connection::inMemory();
            (new Migrator($this->dbInstance, dirname(__DIR__, 3) . '/migrations'))->migrate();
        }

        return $this->dbInstance;
    }

    private function clock(): MutableClock
    {
        return $this->clockInstance ??= new MutableClock();
    }

    private function throttle(): LoginThrottle
    {
        return $this->throttleInstance ??= new LoginThrottle($this->db(), $this->clock());
    }

    public function testNotLockedBeforeThreshold(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            self::assertFalse($this->throttle()->recordFailure('alex', '10.0.0.1'));
        }
        self::assertSame(0, $this->throttle()->lockedFor('alex', '10.0.0.1'));
    }

    public function testFifthFailureLocksAndEscalates(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->throttle()->recordFailure('alex', '10.0.0.1');
        }
        self::assertTrue($this->throttle()->recordFailure('alex', '10.0.0.1'));
        self::assertSame(30, $this->throttle()->lockedFor('alex', '10.0.0.1'));

        $this->clock()->advance(31);
        self::assertSame(0, $this->throttle()->lockedFor('alex', '10.0.0.1'));
        $this->throttle()->recordFailure('alex', '10.0.0.1');
        self::assertSame(60, $this->throttle()->lockedFor('alex', '10.0.0.1'));
    }

    public function testLockIsCappedAtFifteenMinutes(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->throttle()->recordFailure('alex', '10.0.0.1');
        }
        self::assertSame(900, $this->throttle()->lockedFor('alex', '10.0.0.1'));
    }

    public function testNameIsCaseInsensitiveAndUnknownNamesAreCountedToo(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->throttle()->recordFailure('Ghost', '10.0.0.' . $i);
        }
        self::assertGreaterThan(0, $this->throttle()->lockedFor('ghost', '10.9.9.9'));
    }

    public function testIpIsLockedAcrossDifferentNames(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $this->throttle()->recordFailure('user' . $i, '10.0.0.1');
        }
        self::assertGreaterThan(0, $this->throttle()->lockedFor('niemand', '10.0.0.1'));
        self::assertSame(0, $this->throttle()->lockedFor('niemand', '10.0.0.2'));
    }

    public function testFailuresExpireAfterOneHourWithoutNewOnes(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->throttle()->recordFailure('alex', '10.0.0.1');
        }
        $this->clock()->advance(3601);
        self::assertFalse($this->throttle()->recordFailure('alex', '10.0.0.1'));
    }

    public function testSuccessResetsUserButNotIp(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->throttle()->recordFailure('alex', '10.0.0.1');
        }
        $this->throttle()->clearUser('alex');
        self::assertFalse($this->throttle()->recordFailure('alex', '10.0.0.1'));

        $row = $this->db()->fetchOne("SELECT failures FROM login_failures WHERE scope = 'ip'");
        self::assertSame(5, $row['failures'] ?? null);
    }

    public function testOnlyHashesAreStored(): void
    {
        $this->throttle()->recordFailure('TEST-SECRET-do-not-use-123', '203.0.113.9');
        $rows = $this->db()->fetchAll('SELECT scope, subject FROM login_failures');

        foreach ($rows as $row) {
            self::assertStringNotContainsString('TEST-SECRET', (string) json_encode($row));
            self::assertStringNotContainsString('203.0.113.9', (string) json_encode($row));
        }
    }

    public function testUnlockIpFreesOnlyThatIpAndLeavesTheUserCounter(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $this->throttle()->recordFailure('name' . $i, '10.0.0.1');
            $this->throttle()->recordFailure('name' . $i, '10.0.0.2');
        }
        self::assertGreaterThan(0, $this->throttle()->lockedFor('niemand', '10.0.0.1'));

        $this->throttle()->unlockIp('10.0.0.1');

        self::assertSame(0, $this->throttle()->lockedFor('niemand', '10.0.0.1'));
        self::assertGreaterThan(0, $this->throttle()->lockedFor('niemand', '10.0.0.2'));
    }

    public function testParallelProcessesNeverLoseFailures(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'meridian-throttle-');
        self::assertIsString($file);
        $processes = [];

        try {
            (new Migrator(Connection::open($file), dirname(__DIR__, 3) . '/migrations'))->migrate();
            for ($p = 0; $p < 6; ++$p) {
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__, 2) . '/Support/throttle_worker.php', $file, '10'],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            foreach ($processes as [$process, $pipes]) {
                stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                self::assertSame('', $error);
                self::assertSame(0, proc_close($process));
            }

            $db = Connection::open($file);
            $rows = $db->fetchAll('SELECT scope, failures FROM login_failures ORDER BY scope');
            self::assertSame([['scope' => 'ip', 'failures' => 60], ['scope' => 'user', 'failures' => 60]], $rows, '6 Prozesse mal 10 Fehlversuche: nichts darf verloren gehen');
        } finally {
            foreach (['', '-wal', '-shm'] as $suffix) {
                if (is_file($file . $suffix)) {
                    unlink($file . $suffix);
                }
            }
        }
    }

    public function testALongerLockIsNeverShortenedByALaterWrite(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $this->throttle()->recordFailure('alex', '10.0.0.' . $i);
        }
        $long = $this->throttle()->lockedFor('alex', '10.9.9.9');

        $this->db()->execute("UPDATE login_failures SET failures = 5 WHERE scope = 'user'");
        $this->throttle()->recordFailure('alex', '10.0.0.99');

        self::assertGreaterThanOrEqual($long, $this->throttle()->lockedFor('alex', '10.9.9.9'));
    }

    public function testReserveAllowsTheThresholdAndRefusesTheNextAttemptWithoutChecking(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $r = $this->throttle()->reserve('alex', '10.0.0.1');
            self::assertTrue($r->allowed, 'Versuch ' . $i);
            self::assertFalse($r->reachedLock);
        }

        $fifth = $this->throttle()->reserve('alex', '10.0.0.1');
        self::assertTrue($fifth->allowed, 'der fünfte wird noch geprüft');
        self::assertTrue($fifth->reachedLock, 'löst aber die Sperre aus');

        $sixth = $this->throttle()->reserve('alex', '10.0.0.1');
        self::assertFalse($sixth->allowed);
        self::assertGreaterThan(0, $sixth->retryAfter);
    }

    public function testReleaseGivesTheSlotBackAndClearsTheUserCounter(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->throttle()->reserve('alex', '10.0.0.1');
        }
        $fifth = $this->throttle()->reserve('alex', '10.0.0.1');
        self::assertTrue($fifth->reachedLock);

        // Der fünfte Versuch war ein Erfolg: Platz zurück, Sperre des Kontos weg.
        $this->throttle()->release('alex', '10.0.0.1');

        self::assertSame(0, $this->throttle()->lockedFor('alex', '10.9.9.9'));
        self::assertTrue($this->throttle()->reserve('alex', '10.0.0.1')->allowed);
        $ip = self::dig($this->db()->fetchOne("SELECT failures FROM login_failures WHERE scope = 'ip'"), 'failures');
        self::assertSame(5, $ip, 'IP: 6 reserviert, 1 zurückgegeben');
    }

    public function testParallelReservationsNeverLetMoreThanTheThresholdThrough(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'meridian-reserve-');
        self::assertIsString($file);
        $processes = [];

        try {
            (new Migrator(Connection::open($file), dirname(__DIR__, 3) . '/migrations'))->migrate();
            for ($p = 0; $p < 12; ++$p) {
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__, 2) . '/Support/reserve_worker.php', $file],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            $allowed = 0;
            foreach ($processes as [$process, $pipes]) {
                $allowed += (int) stream_get_contents($pipes[1]);
                self::assertSame('', stream_get_contents($pipes[2]));
                self::assertSame(0, proc_close($process));
            }

            self::assertSame(5, $allowed, '12 gleichzeitige Versuche: genau die Schwelle (5) darf geprüft werden, nicht mehr');
        } finally {
            foreach (['', '-wal', '-shm'] as $suffix) {
                if (is_file($file . $suffix)) {
                    unlink($file . $suffix);
                }
            }
        }
    }

    private static function dig(mixed $data, string $key): mixed
    {
        return is_array($data) ? ($data[$key] ?? null) : null;
    }
}
