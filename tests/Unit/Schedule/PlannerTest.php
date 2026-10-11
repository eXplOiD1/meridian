<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Schedule;

use Meridian\Schedule\Planner;
use Meridian\Schedule\RunStatus;
use Meridian\Schedule\SchedulerLease;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\TestCase;

final class PlannerTest extends TestCase
{
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

    public function testDueJobGetsOneQueuedRunAndNextRunFollowsTheSchedule(): void
    {
        $job = $this->h()->addJob(['cron' => '*/10 * * * *', 'next_run_at' => '2026-10-07T08:00:00+00:00']);
        // Drei Minuten verspätet (langsamer Takt): kein „jetzt + Intervall“, der nächste Termin bleibt 08:10.
        $this->h()->advance(180);

        $events = $this->h()->planner->plan(SchedulerHarness::never());

        self::assertCount(1, $events);
        self::assertSame(RunStatus::Queued, $events[0]->status);
        $runs = $this->h()->runs($job);
        self::assertSame('queued', $runs[0]['status']);
        self::assertSame('schedule', $runs[0]['trigger']);
        self::assertSame('2026-10-07T08:00:00+00:00', $runs[0]['scheduled_for']);
        self::assertSame(1, $runs[0]['attempt']);
        self::assertNull($runs[0]['note']);
        self::assertSame('2026-10-07T08:10:00+00:00', $this->h()->nextRunAt($job));

        self::assertSame([], $this->h()->planner->plan(SchedulerHarness::never()), 'derselbe Termin wird nicht zweimal angelegt');
    }

    public function testNothingHappensBeforeTheRunIsDue(): void
    {
        $job = $this->h()->addJob(['next_run_at' => '2026-10-07T08:00:01+00:00']);

        self::assertSame([], $this->h()->planner->plan(SchedulerHarness::never()));
        self::assertSame([], $this->h()->runs($job));
        self::assertSame('2026-10-07T08:00:01+00:00', $this->h()->nextRunAt($job));
    }

    public function testDisabledJobNeverStarts(): void
    {
        $job = $this->h()->addJob(['enabled' => 0, 'next_run_at' => '2026-10-07T07:00:00+00:00']);

        self::assertSame([], $this->h()->planner->plan(SchedulerHarness::never()));
        self::assertSame([], $this->h()->runs($job));
        self::assertSame('2026-10-07T07:00:00+00:00', $this->h()->nextRunAt($job), 'der Planer fasst deaktivierte Jobs nicht an');
    }

    public function testWithoutLeaseNothingIsPlanned(): void
    {
        $job = $this->h()->addJob();
        $this->h()->lease->release();
        $other = new SchedulerLease($this->h()->db, $this->h()->clock, 'anderer-prozess');
        self::assertTrue($other->acquire());

        self::assertSame([], $this->h()->planner->plan(SchedulerHarness::never()));
        self::assertSame([], $this->h()->runs($job));
        self::assertSame('2026-10-07T08:00:00+00:00', $this->h()->nextRunAt($job));
    }

    public function testStopRequestStartsNoFurtherJobs(): void
    {
        $job = $this->h()->addJob();

        self::assertSame([], $this->h()->planner->plan(static fn (): bool => true));
        self::assertSame([], $this->h()->runs($job));
    }

    public function testCatchUpAfterLongDowntimeHappensExactlyOnce(): void
    {
        // Täglich 03:00 Berlin, fünf Tage Stillstand.
        $job = $this->h()->addJob(['cron' => '0 3 * * *', 'catch_up' => 1, 'next_run_at' => '2026-10-02T01:00:00+00:00']);

        $events = $this->h()->planner->plan(SchedulerHarness::never());

        self::assertCount(1, $events);
        $runs = $this->h()->runs($job);
        self::assertSame('queued', $runs[0]['status']);
        self::assertSame(Planner::NOTE_CAUGHT_UP, $runs[0]['note']);
        self::assertSame('2026-10-08T01:00:00+00:00', $this->h()->nextRunAt($job), 'nächster Termin liegt in der Zukunft');

        for ($i = 0; $i < 10; ++$i) {
            $this->h()->clock->advance(5);
            self::assertSame([], $this->h()->planner->plan(SchedulerHarness::never()));
        }
        self::assertCount(1, $this->h()->runs($job), 'nie mehr als ein Nachholen');
    }

    public function testMissedRunIsSkippedWhenCatchUpIsOff(): void
    {
        $job = $this->h()->addJob(['cron' => '0 3 * * *', 'catch_up' => 0, 'next_run_at' => '2026-10-02T01:00:00+00:00']);

        $events = $this->h()->planner->plan(SchedulerHarness::never());

        self::assertSame(RunStatus::Skipped, $events[0]->status);
        $runs = $this->h()->runs($job);
        self::assertCount(1, $runs);
        self::assertSame('skipped', $runs[0]['status']);
        self::assertSame(Planner::NOTE_MISSED_SKIPPED, $runs[0]['note']);
        self::assertSame('2026-10-08T01:00:00+00:00', $this->h()->nextRunAt($job));
        self::assertSame([], $this->h()->planner->plan(SchedulerHarness::never()));
    }

    public function testShortRestartWithinTheGraceWindowIsNotAMissedRun(): void
    {
        // 09:56 Berlin = 07:56 UTC, jetzt 08:00 UTC.
        $job = $this->h()->addJob(['cron' => '56 9 * * *', 'catch_up' => 0, 'next_run_at' => '2026-10-07T07:56:00+00:00']);

        $this->h()->planner->plan(SchedulerHarness::never());

        self::assertSame(['queued'], $this->h()->statuses($job), 'vier Minuten Verspätung: normaler Lauf');
        self::assertNull($this->h()->runs($job)[0]['note']);
    }

    public function testFrequentJobAfterDowntimeRunsOnceForTheCurrentSlot(): void
    {
        foreach ([0, 1] as $catchUp) {
            $job = $this->h()->addJob(['cron' => '* * * * *', 'catch_up' => $catchUp, 'next_run_at' => '2026-10-07T06:00:00+00:00']);
            $this->h()->planner->plan(SchedulerHarness::never());

            $runs = $this->h()->runs($job);
            self::assertCount(1, $runs, 'zwei Stunden Stillstand: ein Lauf, nicht 120');
            self::assertSame('queued', $runs[0]['status']);
            self::assertSame('2026-10-07T08:00:00+00:00', $runs[0]['scheduled_for']);
            self::assertSame(Planner::NOTE_EARLIER_MISSED, $runs[0]['note']);
            self::assertSame('2026-10-07T08:01:00+00:00', $this->h()->nextRunAt($job));
        }
    }

    public function testOverlapSkipRecordsTheSkippedRun(): void
    {
        $job = $this->h()->addJob(['overlap' => 'skip']);
        $this->h()->addRun($job, 'running', SchedulerHarness::OWNER);

        $events = $this->h()->planner->plan(SchedulerHarness::never());

        self::assertSame(RunStatus::Skipped, $events[0]->status);
        self::assertSame(['running', 'skipped'], $this->h()->statuses($job));
        self::assertSame(Planner::NOTE_OVERLAP_SKIP, $this->h()->runs($job)[1]['note']);
        self::assertSame('2026-10-07T08:10:00+00:00', $this->h()->nextRunAt($job), 'auch ein übersprungener Termin schreibt fort');
    }

    public function testOverlapQueueKeepsAtMostOneWaitingRun(): void
    {
        $job = $this->h()->addJob(['overlap' => 'queue', 'cron' => '* * * * *']);
        $this->h()->addRun($job, 'running', SchedulerHarness::OWNER);

        $this->h()->planner->plan(SchedulerHarness::never());
        $this->h()->advance(60);
        $this->h()->planner->plan(SchedulerHarness::never());
        $this->h()->advance(60);
        $this->h()->planner->plan(SchedulerHarness::never());

        self::assertSame(['running', 'queued', 'skipped', 'skipped'], $this->h()->statuses($job));
        self::assertSame(Planner::NOTE_OVERLAP_QUEUE_FULL, $this->h()->runs($job)[2]['note']);
    }

    public function testOverlapParallelQueuesUpToTheLimit(): void
    {
        $job = $this->h()->addJob(['overlap' => 'parallel', 'cron' => '* * * * *']);
        $this->h()->addRun($job, 'running', SchedulerHarness::OWNER);

        for ($i = 0; $i < Planner::MAX_QUEUED_PARALLEL + 1; ++$i) {
            $this->h()->planner->plan(SchedulerHarness::never());
            $this->h()->advance(60);
        }

        $statuses = $this->h()->statuses($job);
        self::assertSame(Planner::MAX_QUEUED_PARALLEL, count(array_keys($statuses, 'queued', true)));
        self::assertSame('skipped', $statuses[count($statuses) - 1]);
    }

    public function testBrokenScheduleIsSkippedWithoutStoppingTheTick(): void
    {
        $badCron = $this->h()->addJob(['cron' => 'kaputt', 'next_run_at' => '2026-10-07T07:00:00+00:00']);
        $badZone = $this->h()->addJob(['timezone' => 'Mars/Olympus', 'next_run_at' => '2026-10-07T07:30:00+00:00']);
        $never = $this->h()->addJob(['cron' => '0 0 30 2 *', 'next_run_at' => '2026-10-07T07:40:00+00:00']);
        $good = $this->h()->addJob(['next_run_at' => '2026-10-07T08:00:00+00:00']);

        $events = $this->h()->planner->plan(SchedulerHarness::never());

        self::assertCount(4, $events);
        foreach ([$badCron, $badZone] as $job) {
            self::assertSame(['skipped'], $this->h()->statuses($job));
            self::assertSame(Planner::NOTE_INVALID_SCHEDULE, $this->h()->runs($job)[0]['note']);
            self::assertNull($this->h()->nextRunAt($job));
        }
        self::assertSame(Planner::NOTE_NO_FURTHER_RUN, $this->h()->runs($never)[0]['note']);
        self::assertNull($this->h()->nextRunAt($never));
        self::assertSame(['queued'], $this->h()->statuses($good));

        $this->h()->advance(3600);
        $this->h()->planner->plan(SchedulerHarness::never());
        self::assertCount(1, $this->h()->runs($badCron), 'ein kaputter Job wird nicht bei jedem Takt erneut vermerkt');
        self::assertStringNotContainsString(SchedulerHarness::FAKE_PAYLOAD, (string) json_encode($this->h()->runs($badCron)));
    }

    public function testRunAndNextRunAreWrittenInOneTransaction(): void
    {
        $job = $this->h()->addJob();
        $this->h()->db->execute("CREATE TEMP TRIGGER runs_fail BEFORE INSERT ON runs BEGIN SELECT RAISE(ABORT, 'Testfehler'); END");

        try {
            $this->h()->planner->plan(SchedulerHarness::never());
            self::fail('Fehler beim Anlegen des Laufs muss durchschlagen');
        } catch (\PDOException) {
            // erwartet
        }

        self::assertSame('2026-10-07T08:00:00+00:00', $this->h()->nextRunAt($job), 'ohne Lauf kein Fortschreiben');
        self::assertSame([], $this->h()->runs($job));
    }

    public function testRescheduleSetsTheNextRunFromNow(): void
    {
        $job = $this->h()->addJob(['cron' => '0 3 * * *', 'next_run_at' => null]);
        $disabled = $this->h()->addJob(['enabled' => 0, 'next_run_at' => '2026-10-07T08:00:00+00:00']);
        $broken = $this->h()->addJob(['cron' => 'kaputt', 'next_run_at' => null]);

        self::assertSame('2026-10-08T01:00:00+00:00', $this->h()->planner->reschedule($job)?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'));
        self::assertSame('2026-10-08T01:00:00+00:00', $this->h()->nextRunAt($job));
        self::assertNull($this->h()->planner->reschedule($disabled));
        self::assertNull($this->h()->nextRunAt($disabled));
        self::assertNull($this->h()->planner->reschedule($broken));
    }
}
