<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Schedule;

use Meridian\Database\Connection;
use Meridian\Runner\Heartbeat;
use Meridian\Runner\JobType;
use Meridian\Runner\RunRequest;
use Meridian\Runner\RunResult;
use Meridian\Schedule\RunStatus;
use Meridian\Schedule\SchedulerLease;
use Meridian\Schedule\Worker;
use Meridian\Tests\Support\FakeRunner;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\TestCase;

/**
 * Lange Läufe: Der Herzschlag hält Lauf und Sperre am Leben. Ein zweiter Scheduler-Prozess darf einen
 * lebenden Lauf weder abbrechen noch denselben Job parallel starten (Befund aus dem Review Phase 2).
 */
final class HeartbeatTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '') {
            SchedulerHarness::removeDatabase($this->file);
        }
    }

    /**
     * @return array{SchedulerHarness, SchedulerHarness}
     */
    private function twoInstances(): array
    {
        $this->file = SchedulerHarness::tempDatabase();
        SchedulerHarness::migrate(Connection::open($this->file));

        return [
            new SchedulerHarness(Connection::open($this->file), '2026-10-07 08:00:00', 'A'),
            new SchedulerHarness(Connection::open($this->file), '2026-10-07 08:00:00', 'B'),
        ];
    }

    public function testLongRunWithHeartbeatIsNeitherAbortedNorDuplicated(): void
    {
        [$a, $b] = $this->twoInstances();
        $job = $a->addJob(['cron' => '* * * * *', 'overlap' => 'skip']);
        $bEvents = [];
        $runner = new FakeRunner(RunResult::ok('fertig'));
        $first = true;
        $runner->during = static function (RunRequest $r, Heartbeat $heartbeat) use ($a, $b, &$bEvents, &$first): void {
            // Nur beim ersten Lauf: ein (fehlerhaft) von B gestarteter Lauf würde hier sonst endlos verschachteln.
            if (!$first) {
                return;
            }
            $first = false;
            // 120 s Laufzeit, alle 15 s ein Herzschlag, dazwischen tickt Prozess B.
            for ($i = 0; $i < 8; ++$i) {
                $a->clock->advance(15);
                $b->clock->advance(15);
                self::assertTrue($heartbeat->beat());
                $bEvents = [...$bEvents, ...$b->scheduler->tick(SchedulerHarness::never())];
            }
        };
        $a->runners->register(JobType::Http, $runner);
        $b->runners->register(JobType::Http, $runner);

        $a->scheduler->tick(SchedulerHarness::never());

        self::assertSame([], $bEvents, 'B bekommt die Sperre nicht und tut nichts');
        self::assertFalse($b->scheduler->holdsLease());
        self::assertCount(1, $runner->requests, 'kein paralleler Lauf');
        self::assertSame(['ok'], $a->statuses($job));
    }

    public function testNewLeaseHolderLeavesARunWithFreshHeartbeatAlone(): void
    {
        [$a, $b] = $this->twoInstances();
        $job = $a->addJob(['cron' => '* * * * *', 'overlap' => 'skip']);
        $runner = new FakeRunner(RunResult::ok());
        $first = true;
        $runner->during = static function (RunRequest $r, Heartbeat $heartbeat) use ($a, $b, &$first): void {
            // Nur beim ersten Lauf: ein (fehlerhaft) von B gestarteter Lauf würde hier sonst endlos verschachteln.
            if (!$first) {
                return;
            }
            $first = false;
            // Sperre geht verloren (z. B. Prozess A hing kurz), der Lauf schlägt aber noch.
            $a->db->execute("UPDATE scheduler_lease SET expires_at = '1970-01-01T00:00:00+00:00'");
            // 70 s später ist der nächste Minutentermin fällig.
            $a->clock->advance(70);
            $b->clock->advance(70);
            $b->db->execute('UPDATE runs SET heartbeat_at = :now', ['now' => $b->now()]);
            $b->scheduler->tick(SchedulerHarness::never());
            self::assertTrue($b->scheduler->holdsLease());
            self::assertTrue($heartbeat->beat(), 'der Lauf gehört weiter A');
        };
        $a->runners->register(JobType::Http, $runner);
        $b->runners->register(JobType::Http, $runner);

        $a->scheduler->tick(SchedulerHarness::never());

        self::assertCount(1, $runner->requests);
        self::assertSame(['ok', 'skipped'], $a->statuses($job), 'B vermerkt den Termin als übersprungen statt parallel zu starten');
    }

    public function testRunWithoutHeartbeatIsAbortedAndItsLateResultIgnored(): void
    {
        [$a, $b] = $this->twoInstances();
        $job = $a->addJob(['retry_count' => 3]);
        $beatAfterAbort = null;
        $runner = new FakeRunner(RunResult::failed());
        $first = true;
        $runner->during = static function (RunRequest $r, Heartbeat $heartbeat) use ($a, $b, &$beatAfterAbort, &$first): void {
            // Nur beim ersten Lauf: ein (fehlerhaft) von B gestarteter Lauf würde hier sonst endlos verschachteln.
            if (!$first) {
                return;
            }
            $first = false;
            $a->clock->advance(SchedulerLease::TTL_SECONDS + 1);
            $b->clock->advance(SchedulerLease::TTL_SECONDS + 1);
            $b->scheduler->tick(SchedulerHarness::never());
            $beatAfterAbort = $heartbeat->beat();
        };
        $a->runners->register(JobType::Http, $runner);

        $a->scheduler->tick(SchedulerHarness::never());

        self::assertFalse($beatAfterAbort, 'Herzschlag meldet den Abbruch an den Runner');
        self::assertSame(['aborted'], $a->statuses($job), 'kein Überschreiben, keine Wiederholung');
        self::assertSame(Worker::NOTE_STALE, $a->runs($job)[0]['note']);
    }

    public function testFailedResultStorageFallsBackToFailed(): void
    {
        $h = new SchedulerHarness();
        $h->runners->register(JobType::Http, new FakeRunner(RunResult::ok()));
        $job = $h->addJob();
        $h->db->execute("CREATE TEMP TRIGGER finish_busy BEFORE UPDATE ON runs WHEN NEW.status = 'ok' BEGIN SELECT RAISE(ABORT, 'database is locked'); END");

        $events = $h->scheduler->tick(SchedulerHarness::never());

        self::assertSame(RunStatus::Failed, $events[1]->status);
        self::assertSame(['failed'], $h->statuses($job), 'nicht auf running hängen geblieben');
        self::assertSame(Worker::NOTE_FINISH_FAILED, $h->runs($job)[0]['note']);
    }

    public function testFallbackNeverOverwritesARunAbortedMeanwhile(): void
    {
        [$a, $b] = $this->twoInstances();
        $job = $a->addJob();
        $runner = new FakeRunner(RunResult::ok());
        $first = true;
        $runner->during = static function (RunRequest $r, Heartbeat $heartbeat) use ($a, $b, &$first): void {
            // Nur beim ersten Lauf: ein (fehlerhaft) von B gestarteter Lauf würde hier sonst endlos verschachteln.
            if (!$first) {
                return;
            }
            $first = false;
            $a->clock->advance(SchedulerLease::TTL_SECONDS + 1);
            $b->clock->advance(SchedulerLease::TTL_SECONDS + 1);
            $b->scheduler->tick(SchedulerHarness::never());
            $a->db->execute("CREATE TEMP TRIGGER finish_busy BEFORE UPDATE ON runs WHEN NEW.status = 'ok' BEGIN SELECT RAISE(ABORT, 'database is locked'); END");
        };
        $a->runners->register(JobType::Http, $runner);

        $events = $a->scheduler->tick(SchedulerHarness::never());

        $last = end($events);
        self::assertNotFalse($last);
        self::assertSame(RunStatus::Aborted, $last->status);
        self::assertSame(['aborted'], $a->statuses($job));
        self::assertSame(Worker::NOTE_STALE, $a->runs($job)[0]['note']);
    }

    public function testOwnRunStuckOnRunningIsAbortedOnceItsHeartbeatIsStale(): void
    {
        $h = new SchedulerHarness();
        $h->runners->register(JobType::Http, new FakeRunner(RunResult::ok()));
        $job = $h->addJob(['next_run_at' => '2026-10-07T08:00:00+00:00', 'cron' => '0 3 * * *']);
        $h->db->execute("CREATE TEMP TRIGGER finish_busy BEFORE UPDATE ON runs WHEN NEW.status <> 'running' BEGIN SELECT RAISE(ABORT, 'database is locked'); END");

        try {
            $h->scheduler->tick(SchedulerHarness::never());
            self::fail('Speichern ist dauerhaft gescheitert, das muss durchschlagen');
        } catch (\PDOException) {
            // erwartet
        }
        self::assertSame(['running'], $h->statuses($job));
        $h->db->execute('DROP TRIGGER temp.finish_busy');

        $h->advance(30);
        $h->scheduler->tick(SchedulerHarness::never());
        self::assertSame(['running'], $h->statuses($job), 'Herzschlag noch frisch');

        $h->advance(SchedulerLease::TTL_SECONDS);
        $h->scheduler->tick(SchedulerHarness::never());
        self::assertSame(['aborted'], $h->statuses($job), 'eigener hängender Lauf wird beendet');
    }

    public function testDatabaseErrorDuringHeartbeatIsNotAnAbort(): void
    {
        $h = new SchedulerHarness();
        $job = $h->addJob();
        $results = [];
        $runner = new FakeRunner(RunResult::ok('fertig'));
        $runner->during = static function (RunRequest $r, Heartbeat $heartbeat) use ($h, &$results): void {
            $h->db->execute("CREATE TEMP TRIGGER beat_busy BEFORE UPDATE OF heartbeat_at ON runs BEGIN SELECT RAISE(ABORT, 'database is locked'); END");
            $h->clock->advance(10);
            $results[] = $heartbeat->beat();
            $h->db->execute('DROP TRIGGER temp.beat_busy');
            // Kein Drosseln nach dem Fehler: der nächste Aufruf schreibt sofort.
            $h->clock->advance(1);
            $results[] = $heartbeat->beat();
        };
        $h->runners->register(JobType::Http, $runner);

        $h->scheduler->tick(SchedulerHarness::never());

        self::assertSame([true, true], $results, 'Datenbankfehler ist kein Abbruch');
        self::assertSame(['ok'], $h->statuses($job), 'Lauf endet regulär, keine Wiederholung');
        self::assertSame('fertig', $h->runs($job)[0]['output']);
        self::assertSame('2026-10-07T08:00:11+00:00', $h->db->fetchOne('SELECT heartbeat_at FROM runs')['heartbeat_at'] ?? null);
    }

    public function testHeartbeatWritesAreThrottled(): void
    {
        $h = new SchedulerHarness();
        self::assertTrue($h->lease->acquire());
        $job = $h->addJob();
        $run = $h->addRun($job, 'queued');
        $request = $h->worker->claim($run);
        self::assertInstanceOf(RunRequest::class, $request);
        $beat = new \Meridian\Schedule\RunHeartbeat($h->db, $h->clock, $h->worker->identity()->id, $run);

        self::assertTrue($beat->beat());
        $h->clock->advance(2);
        self::assertTrue($beat->beat());
        self::assertSame('2026-10-07T08:00:00+00:00', $h->db->fetchOne('SELECT heartbeat_at FROM runs')['heartbeat_at'] ?? null);
        $h->clock->advance(5);
        self::assertTrue($beat->beat());
        self::assertSame('2026-10-07T08:00:07+00:00', $h->db->fetchOne('SELECT heartbeat_at FROM runs')['heartbeat_at'] ?? null);
    }
}
