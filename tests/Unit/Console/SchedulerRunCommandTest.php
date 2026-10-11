<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Console;

use Meridian\Console\SchedulerRunCommand;
use Meridian\Database\Connection;
use Meridian\Runner\JobType;
use Meridian\Tests\Support\FakeRunner;
use Meridian\Schedule\SchedulerLease;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SchedulerRunCommandTest extends TestCase
{
    public function testOnceRunsExactlyOneTickAndPrintsOnlyIdsAndStatus(): void
    {
        $h = new SchedulerHarness();
        $job = $h->addJob();
        $tester = new CommandTester(new SchedulerRunCommand($h->scheduler));

        $tester->execute(['--once' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("Job {$job}, Lauf 1: queued\nJob {$job}, Lauf 1: failed\n", $tester->getDisplay(true));
        self::assertStringNotContainsString(SchedulerHarness::FAKE_PAYLOAD, $tester->getDisplay(true));
        self::assertFalse($h->lease->isHeld(), 'Sperre nach dem Durchlauf freigegeben');
    }

    /**
     * Ausnahmen aus Planer, Worker und Runner tragen ein Test-Geheimnis in der Meldung: es darf nie in der
     * Ausgabe des Dienstes erscheinen.
     */
    public function testExceptionMessagesNeverReachTheOutput(): void
    {
        $secret = 'TEST-GEHEIMNIS-unecht-4711';

        // Planer: Anlegen des Laufs scheitert.
        $planner = new SchedulerHarness();
        $planner->addJob();
        $planner->db->execute("CREATE TEMP TRIGGER leak BEFORE INSERT ON runs BEGIN SELECT RAISE(ABORT, 'TEST-GEHEIMNIS-unecht-4711'); END");
        // Worker und Runner: der Runner wirft, das Speichern scheitert dauerhaft.
        $worker = new SchedulerHarness();
        $worker->runners->register(JobType::Http, new FakeRunner(new \RuntimeException('Runner mit ' . $secret)));
        $worker->addJob();
        $worker->db->execute("CREATE TEMP TRIGGER leak BEFORE UPDATE ON runs WHEN NEW.status <> 'running' BEGIN SELECT RAISE(ABORT, 'TEST-GEHEIMNIS-unecht-4711'); END");

        foreach ([$planner, $worker] as $h) {
            $tester = new CommandTester(new SchedulerRunCommand($h->scheduler));
            $tester->execute(['--once' => true], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_DEBUG]);

            self::assertStringContainsString('Takt fehlgeschlagen (PDOException)', $tester->getDisplay(true));
            self::assertStringNotContainsString($secret, $tester->getDisplay(true));
            self::assertStringNotContainsString('TEST-GEHEIM', $tester->getDisplay(true));
        }
    }

    public function testWaitsWhenAnotherSchedulerHoldsTheLease(): void
    {
        $h = new SchedulerHarness();
        $job = $h->addJob();
        self::assertTrue((new SchedulerLease($h->db, $h->clock, 'anderer'))->acquire());
        $tester = new CommandTester(new SchedulerRunCommand($h->scheduler));

        $tester->execute(['--once' => true]);

        self::assertStringContainsString('Ein anderer Scheduler hält die Sperre', $tester->getDisplay(true));
        self::assertSame([], $h->runs($job));
    }

    public function testSigtermEndsTheProcessCleanlyAndReleasesTheLease(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            self::markTestSkipped('pcntl fehlt');
        }
        $dir = sys_get_temp_dir() . '/meridian-sched-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700);
        $db = Connection::open($dir . '/meridian.sqlite');
        SchedulerHarness::migrate($db);

        // Zufälliger Schlüssel je Test, nie ein Literal im Quelltext.
        file_put_contents($dir . '/key', \Meridian\Security\KeyLoader::generate() . "\n");
        chmod($dir . '/key', 0o600);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/meridian', 'scheduler:run', '-v'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['MERIDIAN_DATA_DIR' => $dir, 'MERIDIAN_KEY_FILE' => $dir . '/key', 'PATH' => '/usr/bin:/bin'],
        );
        self::assertIsResource($process);

        try {
            $held = false;
            for ($i = 0; $i < 100 && !$held; ++$i) {
                usleep(100_000);
                $held = $db->fetchOne("SELECT 1 AS held FROM scheduler_lease WHERE owner <> ''") !== null;
            }
            self::assertTrue($held, 'der Prozess übernimmt die Sperre');

            $started = microtime(true);
            proc_terminate($process, SIGTERM);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            $code = proc_close($process);

            self::assertSame(0, $code, $stderr);
            self::assertLessThan(5.0, microtime(true) - $started, 'beendet sich zügig');
            self::assertStringContainsString('Scheduler beendet (Signal).', $stdout);
            self::assertSame(['owner' => ''], $db->fetchOne('SELECT owner FROM scheduler_lease'), 'Sperre freigegeben');
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, SIGKILL);
            }
            SchedulerHarness::removeDatabase($dir . '/meridian.sqlite');
            unlink($dir . '/key');
            rmdir($dir);
        }
    }

    public function testHttpWorkerWithoutReadableKeyEndsBeforeClaimingWork(): void
    {
        $dir = sys_get_temp_dir() . '/meridian-sched-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700);
        $db = Connection::open($dir . '/meridian.sqlite');
        SchedulerHarness::migrate($db);
        try {
            foreach ([[], ['MERIDIAN_KEY_FILE' => $dir . '/fehlt']] as $keyEnv) {
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__, 3) . '/bin/meridian', 'worker:run', '--type=http', '--child', '--once'],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    null,
                    ['MERIDIAN_DATA_DIR' => $dir, 'PATH' => '/usr/bin:/bin'] + $keyEnv,
                );
                self::assertIsResource($process);
                $stdout = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
                self::assertSame(1, proc_close($process));
                self::assertMatchesRegularExpression('/Kein Schlüssel gesetzt|Schlüsseldatei ist nicht lesbar/', $stdout);
                self::assertSame(['owner' => ''], $db->fetchOne('SELECT owner FROM scheduler_lease'), 'Sperre nie übernommen');
            }
        } finally {
            SchedulerHarness::removeDatabase($dir . '/meridian.sqlite');
            rmdir($dir);
        }
    }
}
