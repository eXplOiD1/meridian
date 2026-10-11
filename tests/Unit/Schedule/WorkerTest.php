<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Schedule;

use Meridian\Database\Connection;
use Meridian\Runner\JobType;
use Meridian\Runner\RunRequest;
use Meridian\Runner\RunResult;
use Meridian\Schedule\RetryPolicy;
use Meridian\Schedule\RunEvent;
use Meridian\Schedule\RunStatus;
use Meridian\Schedule\RunTrigger;
use Meridian\Schedule\SchedulerLease;
use Meridian\Schedule\Worker;
use Meridian\Tests\Support\FakeRunner;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    /** Offensichtlich unechtes Test-Geheimnis. */
    private const TEST_SECRET = 'TEST-GEHEIMNIS-unecht-4711';

    private ?SchedulerHarness $harness = null;

    /**
     * Frische Umgebung je Test, Sperre bereits übernommen.
     */
    private function h(): SchedulerHarness
    {
        if ($this->harness === null) {
            $this->harness = new SchedulerHarness();
            self::assertTrue($this->harness->lease->acquire());
        }

        return $this->harness;
    }

    public function testRunWithoutRegisteredRunnerFailsWithNoteAndIsNotRetried(): void
    {
        $job = $this->h()->addJob(['retry_count' => 3]);
        $run = $this->h()->addRun($job, 'queued');

        $events = $this->h()->worker->work(SchedulerHarness::never());

        self::assertEquals([new RunEvent($job, $run, RunStatus::Failed)], $events);
        $runs = $this->h()->runs($job);
        self::assertCount(1, $runs, 'fehlender Runner: Wiederholen hilft nicht');
        self::assertSame(Worker::NOTE_NO_RUNNER, $runs[0]['note']);
        self::assertSame('Für diesen Job-Typ ist noch kein Runner eingerichtet.', Worker::NOTE_NO_RUNNER);
    }

    public function testSuccessfulRunStoresResultAndDuration(): void
    {
        $runner = new FakeRunner(RunResult::ok('alles gut', null, 200));
        $this->h()->runners->register(JobType::Http, $runner);
        $job = $this->h()->addJob();
        $run = $this->h()->addRun($job, 'queued', null, '2026-10-07T08:00:00+00:00');

        $events = $this->h()->worker->work(SchedulerHarness::never());

        self::assertEquals([new RunEvent($job, $run, RunStatus::Ok)], $events);
        self::assertCount(1, $runner->requests);
        self::assertSame($run, $runner->requests[0]->runId);
        self::assertSame(JobType::Http, $runner->requests[0]->type);
        $row = $this->h()->db->fetchOne('SELECT status, output, http_status, started_at, finished_at, duration_ms, worker FROM runs WHERE id = :id', ['id' => $run]);
        self::assertSame(['status' => 'ok', 'output' => 'alles gut', 'http_status' => 200, 'started_at' => '2026-10-07T08:00:00+00:00', 'finished_at' => '2026-10-07T08:00:00+00:00', 'duration_ms' => 0, 'worker' => SchedulerHarness::OWNER], $row);
    }

    public function testOutputAndNoteAreMaskedBeforeStoring(): void
    {
        $runner = new FakeRunner(
            RunResult::failed('Antwort: ' . self::TEST_SECRET . ' url=https://x.example/?token=abc123def', 'Fehler bei ' . self::TEST_SECRET),
        );
        $runner->secrets = [self::TEST_SECRET];
        $this->h()->runners->register(JobType::Http, $runner);
        $job = $this->h()->addJob();
        $run = $this->h()->addRun($job, 'queued');

        $this->h()->worker->work(SchedulerHarness::never());

        $raw = (string) json_encode($this->h()->db->fetchAll('SELECT * FROM runs'));
        self::assertStringNotContainsString(self::TEST_SECRET, $raw);
        self::assertStringNotContainsString('abc123def', $raw);
        $stored = $this->h()->runs($job)[0];
        self::assertSame($run, $stored['id']);
        self::assertStringContainsString('••••', (string) $stored['output']);
        self::assertSame('Fehler bei ••••', $stored['note']);
    }

    public function testPlannerNoteSurvivesTheResult(): void
    {
        $this->h()->runners->register(JobType::Http, new FakeRunner(RunResult::ok(), RunResult::failed('', 'Ziel antwortet nicht.')));
        $job = $this->h()->addJob(['overlap' => 'parallel']);
        $this->h()->db->execute("INSERT INTO runs (job_id, trigger, status, note) VALUES (:j, 'schedule', 'queued', 'Nachgeholt.'), (:j, 'schedule', 'queued', 'Nachgeholt.')", ['j' => $job]);

        $this->h()->worker->work(SchedulerHarness::never());

        self::assertSame(['Nachgeholt.', 'Nachgeholt. Ziel antwortet nicht.'], array_column($this->h()->runs($job), 'note'));
    }

    public function testLongOutputIsMaskedFirstThenTruncated(): void
    {
        // Das Geheimnis liegt genau auf der Schnittkante: Kürzen vor dem Maskieren würde einen Teil freilegen.
        $output = str_repeat('a', Worker::MAX_OUTPUT_BYTES - 20) . self::TEST_SECRET . str_repeat('ü', 5000);
        $runner = new FakeRunner(RunResult::ok($output));
        $runner->secrets = [self::TEST_SECRET];
        $this->h()->runners->register(JobType::Http, $runner);
        $job = $this->h()->addJob();
        $this->h()->addRun($job, 'queued');

        $this->h()->worker->work(SchedulerHarness::never());

        $stored = (string) $this->h()->runs($job)[0]['output'];
        self::assertLessThanOrEqual(Worker::MAX_OUTPUT_BYTES, strlen($stored));
        self::assertStringEndsWith('[gekürzt]', $stored);
        self::assertStringNotContainsString('TEST-GEHEIM', $stored);
        self::assertSame(1, preg_match('//u', $stored), 'kein angeschnittenes UTF-8-Zeichen');
    }

    public function testRunnerExceptionNeverLeaksItsMessage(): void
    {
        $this->h()->runners->register(JobType::Http, new FakeRunner(new \RuntimeException('kaputt mit ' . self::TEST_SECRET)));
        $job = $this->h()->addJob();
        $this->h()->addRun($job, 'queued');

        $this->h()->worker->work(SchedulerHarness::never());

        $stored = $this->h()->runs($job)[0];
        self::assertSame('failed', $stored['status']);
        self::assertSame(Worker::NOTE_RUNNER_ERROR, $stored['note']);
        self::assertStringNotContainsString(self::TEST_SECRET, (string) json_encode($this->h()->db->fetchAll('SELECT * FROM runs')));
    }

    public function testRetriesWithGrowingDelayUpToRetryCount(): void
    {
        $runner = new FakeRunner(RunResult::failed('nein'), RunResult::timeout(), RunResult::failed('nein'));
        $this->h()->runners->register(JobType::Http, $runner);
        $job = $this->h()->addJob(['retry_count' => 2, 'retry_delay' => 60]);
        $this->h()->addRun($job, 'queued');

        $this->h()->worker->work(SchedulerHarness::never());
        $runs = $this->h()->runs($job);
        self::assertSame(['failed', 'queued'], array_column($runs, 'status'));
        self::assertSame('retry', $runs[1]['trigger']);
        self::assertSame(2, $runs[1]['attempt']);
        self::assertSame('2026-10-07T08:01:00+00:00', $runs[1]['scheduled_for'], 'Abstand 60 s');

        $this->h()->advance(59);
        self::assertSame([], $this->h()->worker->work(SchedulerHarness::never()), 'Wiederholung startet nicht vor ihrem Termin');
        $this->h()->advance(1);
        $this->h()->worker->work(SchedulerHarness::never());
        $runs = $this->h()->runs($job);
        self::assertSame(['failed', 'timeout', 'queued'], array_column($runs, 'status'), 'auch Zeitüberschreitung wird wiederholt');
        self::assertSame(3, $runs[2]['attempt']);
        self::assertSame('2026-10-07T08:03:00+00:00', $runs[2]['scheduled_for'], 'Abstand verdoppelt: 120 s');

        $this->h()->advance(120);
        $this->h()->worker->work(SchedulerHarness::never());
        self::assertSame(['failed', 'timeout', 'failed'], $this->h()->statuses($job), 'nach retry_count Wiederholungen ist Schluss');
        self::assertCount(3, $runner->requests);
    }

    public function testSuccessfulAndTestRunsAreNotRetried(): void
    {
        $this->h()->runners->register(JobType::Http, new FakeRunner(RunResult::ok(), RunResult::failed()));
        $job = $this->h()->addJob(['retry_count' => 3]);
        $this->h()->addRun($job, 'queued');
        $this->h()->addRun($job, 'queued', null, null, 'test', $this->h()->addUser('admin', 'Admin'));

        $this->h()->worker->work(SchedulerHarness::never());

        self::assertSame(['ok', 'failed'], $this->h()->statuses($job));
    }

    public function testRetryDelayGrowsAndIsCapped(): void
    {
        self::assertSame([60, 120, 240, 480, 960, 1920, 3600, 3600], array_map(static fn (int $a): int => RetryPolicy::delaySeconds(60, $a), range(1, 8)));
        self::assertSame(3600, RetryPolicy::delaySeconds(3600, 1));
        self::assertSame(3600, RetryPolicy::delaySeconds(1, PHP_INT_MAX), 'kein Überlauf');
        self::assertSame(1, RetryPolicy::delaySeconds(0, 1));
        self::assertTrue(RetryPolicy::allowsAnother(2, 2));
        self::assertFalse(RetryPolicy::allowsAnother(2, 3));
        self::assertFalse(RetryPolicy::allowsAnother(-1, 1));
        self::assertFalse(RetryPolicy::allowsAnother(1000, RetryPolicy::MAX_RETRIES + 1), 'Obergrenze gegen endloses Wiederholen');
    }

    public function testClaimIsAtomicBetweenTwoWorkers(): void
    {
        $file = SchedulerHarness::tempDatabase();
        try {
            $db = Connection::open($file);
            SchedulerHarness::migrate($db);
            $a = new SchedulerHarness($db);
            $b = new SchedulerHarness(Connection::open($file));
            self::assertTrue($a->lease->acquire());
            $job = $a->addJob();
            $run = $a->addRun($job, 'queued');

            $first = $a->worker->claim($run);
            $second = $b->worker->claim($run);

            self::assertInstanceOf(RunRequest::class, $first);
            self::assertNull($second, 'ein Lauf wird genau einmal übernommen');
            self::assertSame(['running'], $b->statuses($job));
        } finally {
            SchedulerHarness::removeDatabase($file);
        }
    }

    public function testParallelProcessesNeverClaimTheSameRunTwice(): void
    {
        $file = SchedulerHarness::tempDatabase();
        $processes = [];
        try {
            $db = Connection::open($file);
            SchedulerHarness::migrate($db);
            $h = new SchedulerHarness($db);
            $job = $h->addJob(['overlap' => 'parallel']);
            for ($i = 0; $i < 40; ++$i) {
                $h->addRun($job, 'queued');
            }
            // Sperre für die Hilfsprozesse (echte Uhr) weit in die Zukunft setzen.
            $db->execute("UPDATE scheduler_lease SET owner = 'gemeinsam', expires_at = '2999-01-01T00:00:00+00:00'");

            for ($p = 0; $p < 4; ++$p) {
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__, 2) . '/Support/claim_worker.php', $file, 'gemeinsam'],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            $claimed = [];
            foreach ($processes as [$process, $pipes]) {
                $out = (string) stream_get_contents($pipes[1]);
                self::assertSame('', stream_get_contents($pipes[2]));
                self::assertSame(0, proc_close($process));
                foreach (array_filter(explode("\n", $out), static fn (string $line): bool => $line !== '') as $id) {
                    $claimed[] = (int) $id;
                }
            }

            self::assertCount(40, $claimed, 'jeder Lauf genau einmal');
            self::assertCount(40, array_unique($claimed));
        } finally {
            SchedulerHarness::removeDatabase($file);
        }
    }

    public function testWorkerClaimsIndependentlyOfSchedulerLease(): void
    {
        $job = $this->h()->addJob();
        $run = $this->h()->addRun($job, 'queued');
        $this->h()->lease->release();
        self::assertTrue((new SchedulerLease($this->h()->db, $this->h()->clock, 'anderer'))->acquire());

        self::assertInstanceOf(RunRequest::class, $this->h()->worker->claim($run));
        self::assertSame(['running'], $this->h()->statuses($job));
        self::assertFalse($this->h()->lease->isHeld());
    }

    public function testRunOfDisabledJobIsSkippedNotExecuted(): void
    {
        $runner = new FakeRunner();
        $this->h()->runners->register(JobType::Http, $runner);
        $job = $this->h()->addJob(['enabled' => 0]);
        $run = $this->h()->addRun($job, 'queued');

        $events = $this->h()->worker->work(SchedulerHarness::never());

        self::assertEquals([new RunEvent($job, $run, RunStatus::Skipped)], $events);
        self::assertSame([], $runner->requests);
        self::assertSame(Worker::NOTE_JOB_DISABLED, $this->h()->runs($job)[0]['note']);
    }

    public function testQueuedRunWaitsWhileAnotherRunOfTheJobIsRunning(): void
    {
        $job = $this->h()->addJob(['overlap' => 'queue']);
        $this->h()->addRun($job, 'running', SchedulerHarness::OWNER);
        $waiting = $this->h()->addRun($job, 'queued');

        self::assertNull($this->h()->worker->claim($waiting));
        self::assertSame(['running', 'queued'], $this->h()->statuses($job));
    }

    public function testStaleRunningRunsAreAbortedAndNeverRestarted(): void
    {
        $runner = new FakeRunner();
        $this->h()->runners->register(JobType::Http, $runner);
        $job = $this->h()->addJob(['retry_count' => 3]);
        $stale = $this->h()->addRun($job, 'running', 'toter-prozess');
        $legacy = $this->h()->addRun($job, 'running');
        $mine = $this->h()->addRun($job, 'running', SchedulerHarness::OWNER);
        $this->h()->db->execute('UPDATE runs SET heartbeat_at = :now WHERE id = :id', ['now' => $this->h()->now(), 'id' => $mine]);

        $events = $this->h()->staleRuns->abort();

        self::assertEquals([new RunEvent($job, $stale, RunStatus::Aborted), new RunEvent($job, $legacy, RunStatus::Aborted)], $events);
        self::assertSame(['aborted', 'aborted', 'running'], $this->h()->statuses($job));
        self::assertSame(Worker::NOTE_STALE, $this->h()->runs($job)[0]['note']);
        $this->h()->worker->work(SchedulerHarness::never());
        self::assertSame([], $runner->requests, 'abgebrochene Läufe werden nicht neu gestartet');
        self::assertCount(3, $this->h()->runs($job), 'und nicht wiederholt');
        self::assertSame($mine, $this->h()->runs($job)[2]['id']);
    }

    public function testLateFinishOfAnAbortedRunChangesNothing(): void
    {
        $this->h()->runners->register(JobType::Http, new FakeRunner(RunResult::failed()));
        $job = $this->h()->addJob(['retry_count' => 3]);
        $run = $this->h()->addRun($job, 'queued');
        $request = $this->h()->worker->claim($run);
        self::assertInstanceOf(RunRequest::class, $request);

        // Ein neuer Prozess übernimmt die Sperre und bricht den Lauf als hängend ab.
        $this->h()->clock->advance(SchedulerLease::TTL_SECONDS);
        $other = new SchedulerHarness($this->h()->db, '2026-10-07 08:01:00', 'nachfolger');
        self::assertTrue($other->lease->acquire());
        $other->staleRuns->abort();

        $event = $this->h()->worker->execute($request);

        self::assertSame(RunStatus::Aborted, $event->status);
        self::assertSame(['aborted'], $this->h()->statuses($job), 'kein Überschreiben, keine Wiederholung');
    }
    public function testEachRunGetsItsOwnMasker(): void
    {
        $runner = new FakeRunner(RunResult::ok('erster'), RunResult::ok('zweiter ' . self::TEST_SECRET));
        $this->h()->runners->register(JobType::Http, $runner);
        $job = $this->h()->addJob(['overlap' => 'parallel']);
        $this->h()->addRun($job, 'queued');
        $this->h()->addRun($job, 'queued');
        // Nur der erste Lauf kennt das Geheimnis (wie ein Runner, der die Payload von Job 1 registriert).
        $runner->during = static function (RunRequest $r, \Meridian\Runner\Heartbeat $hb) use ($runner): void {
            if (count($runner->maskers) === 1) {
                end($runner->maskers)->remember(self::TEST_SECRET);
            }
        };

        $this->h()->worker->work(SchedulerHarness::never());

        self::assertCount(2, $runner->maskers);
        self::assertNotSame($runner->maskers[0], $runner->maskers[1], 'H4: je Lauf ein eigener Masker');
        self::assertSame('••••', $runner->maskers[0]->mask(self::TEST_SECRET));
        self::assertSame(self::TEST_SECRET, $runner->maskers[1]->mask(self::TEST_SECRET), 'Geheimnis aus Lauf 1 nicht im Masker von Lauf 2');
    }

    public function testNotRetryableResultIsNeverRetried(): void
    {
        $this->h()->runners->register(JobType::Http, new FakeRunner(RunResult::failed('', 'Ziel gesperrt.', retryable: false)));
        $job = $this->h()->addJob(['retry_count' => 3]);
        $this->h()->addRun($job, 'queued');

        $this->h()->worker->work(SchedulerHarness::never());

        self::assertSame(['failed'], $this->h()->statuses($job), 'Einrichtungsfehler: keine Wiederholung');
    }

    public function testTestRunOfDisabledJobRunsButManualRunIsSkipped(): void
    {
        $runner = new FakeRunner();
        $this->h()->runners->register(JobType::Http, $runner);
        $admin = $this->h()->addUser('admin', 'Admin');
        $job = $this->h()->addJob(['enabled' => 0, 'overlap' => 'parallel']);
        $test = $this->h()->addRun($job, 'queued', null, null, 'test', $admin);
        $manual = $this->h()->addRun($job, 'queued', null, null, 'manual', $admin);

        $events = $this->h()->worker->work(SchedulerHarness::never());

        self::assertEquals([new RunEvent($job, $test, RunStatus::Ok), new RunEvent($job, $manual, RunStatus::Skipped)], $events);
        self::assertCount(1, $runner->requests);
        self::assertSame(RunTrigger::Test, $runner->requests[0]->trigger);
        self::assertSame($admin, $runner->requests[0]->startedBy);
        self::assertSame(Worker::NOTE_JOB_DISABLED, $this->h()->runs($job)[1]['note']);
    }

    /**
     * H1: Zwischen Einreihen und Übernehmen ändert sich etwas → der Lauf startet nicht.
     *
     * @return array<string, array{0: string}>
     */
    public static function revocations(): array
    {
        return [
            'Benutzer deaktiviert' => ['deactivate'],
            'Benutzer gelöscht' => ['delete'],
            'Recht entzogen' => ['revoke'],
            'Job in fremde Kategorie verschoben' => ['move'],
            'Job ohne Kategorie' => ['uncategorize'],
            'Beschränkung auf andere Kategorie' => ['restrict'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('revocations')]
    public function testManualAndTestRunsAreSkippedWhenTheStarterMayNoLongerRun(string $change): void
    {
        $h = $this->h();
        $runner = new FakeRunner();
        $h->runners->register(JobType::Http, $runner);
        $a = $h->addCategory('A');
        $b = $h->addCategory('B');
        $operator = $h->addUser('op-a', 'Operator', ['A']);
        $job = $h->addJob(['category_id' => $a, 'overlap' => 'parallel']);
        $manual = $h->addRun($job, 'queued', null, null, 'manual', $operator);
        $test = $h->addRun($job, 'queued', null, null, 'test', $operator);
        $retry = $h->addRun($job, 'queued', null, null, 'retry', $operator);

        match ($change) {
            'deactivate' => $h->db->execute('UPDATE users SET is_active = 0 WHERE id = :u', ['u' => $operator]),
            'delete' => $h->db->execute('DELETE FROM users WHERE id = :u', ['u' => $operator]),
            'revoke' => $h->db->execute("DELETE FROM role_permissions WHERE permission = 'jobs.run' AND role_id = (SELECT id FROM roles WHERE name = 'Operator')"),
            'move' => $h->db->execute('UPDATE jobs SET category_id = :b WHERE id = :j', ['b' => $b, 'j' => $job]),
            'uncategorize' => $h->db->execute('DELETE FROM categories WHERE id = :a', ['a' => $a]),
            'restrict' => $h->db->execute('UPDATE user_role_categories SET category_id = :b', ['b' => $b]),
            default => self::fail('unbekannt'),
        };

        $h->worker->work(SchedulerHarness::never());

        $runs = $h->runs($job);
        self::assertSame([$manual, $test, $retry], array_column($runs, 'id'));
        if ($change === 'delete') {
            // started_by wird NULL (ON DELETE SET NULL): manuell/Test ohne Benutzer starten nie. Eine Wiederholung
            // ohne Benutzer ist nach 0003 §4.5 (H1) nicht mehr von der eines geplanten Laufs unterscheidbar und läuft
            // wie diese (der Job ist aktiv und läuft ohnehin nach Zeitplan).
            self::assertSame(['skipped', 'skipped', 'ok'], array_column($runs, 'status'));
            self::assertCount(1, $runner->requests);
            self::assertSame(RunTrigger::Retry, $runner->requests[0]->trigger);

            return;
        }
        self::assertSame([], $runner->requests, 'nichts ausgeführt');
        self::assertSame(['skipped', 'skipped', 'skipped'], array_column($runs, 'status'));
        foreach ($runs as $run) {
            self::assertSame(Worker::NOTE_STARTER_FORBIDDEN, $run['note']);
        }
    }

    public function testManualRunOfAllowedStarterRunsAndRetryKeepsTheStarter(): void
    {
        $h = $this->h();
        $runner = new FakeRunner(RunResult::failed('nein'));
        $h->runners->register(JobType::Http, $runner);
        $a = $h->addCategory('A');
        $operator = $h->addUser('op-a', 'Operator', ['A']);
        $job = $h->addJob(['category_id' => $a, 'retry_count' => 1, 'retry_delay' => 60]);
        $h->addRun($job, 'queued', null, null, 'manual', $operator);

        $h->worker->work(SchedulerHarness::never());

        self::assertCount(1, $runner->requests);
        self::assertSame($operator, $runner->requests[0]->startedBy);
        $retry = $h->db->fetchOne("SELECT trigger, started_by FROM runs WHERE status = 'queued'");
        self::assertSame(['trigger' => 'retry', 'started_by' => $operator], $retry, 'Wiederholung übernimmt started_by');

        // Vor der Wiederholung wird das Recht entzogen: die Wiederholung startet nicht.
        $h->db->execute('UPDATE users SET is_active = 0 WHERE id = :u', ['u' => $operator]);
        $h->advance(60);
        $h->worker->work(SchedulerHarness::never());
        self::assertCount(1, $runner->requests);
        self::assertSame(['failed', 'skipped'], $h->statuses($job));
    }

    public function testManualOrTestRunWithoutStarterNeverRuns(): void
    {
        $runner = new FakeRunner();
        $this->h()->runners->register(JobType::Http, $runner);
        $job = $this->h()->addJob(['overlap' => 'parallel']);
        $this->h()->addRun($job, 'queued', null, null, 'manual');
        $this->h()->addRun($job, 'queued', null, null, 'test');
        $this->h()->addRun($job, 'queued', null, null, 'retry');
        $this->h()->addRun($job, 'queued');

        $this->h()->worker->work(SchedulerHarness::never());

        self::assertSame(['skipped', 'skipped', 'ok', 'ok'], $this->h()->statuses($job));
        self::assertCount(2, $runner->requests);
    }

    public function testAuthorizerFailureMeansNoStart(): void
    {
        $h = $this->h();
        $runner = new FakeRunner();
        $h->runners->register(JobType::Http, $runner);
        $throwing = new class implements \Meridian\Schedule\RunAuthorizer {
            public function mayStart(int $userId, int $jobId): bool
            {
                throw new \RuntimeException('kaputt');
            }
        };
        $worker = new Worker($h->db, $h->clock, $h->worker->identity(), [JobType::Http], $h->runners, $throwing);
        $admin = $h->addUser('admin', 'Admin');
        $job = $h->addJob();
        $h->addRun($job, 'queued', null, null, 'manual', $admin);

        $worker->work(SchedulerHarness::never());

        self::assertSame([], $runner->requests);
        self::assertSame(['skipped'], $h->statuses($job));
    }
}
