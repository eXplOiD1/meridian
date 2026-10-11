<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Schedule;

use Meridian\Runner\JobType;
use Meridian\Runner\RunResult;
use Meridian\Schedule\RunStatus;
use Meridian\Schedule\SchedulerLease;
use Meridian\Tests\Support\FakeRunner;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{
    public function testTickPlansAndExecutesInOneGo(): void
    {
        $h = new SchedulerHarness();
        $h->runners->register(JobType::Http, new FakeRunner(RunResult::ok()));
        $job = $h->addJob();

        $events = $h->scheduler->tick(SchedulerHarness::never());

        self::assertSame([RunStatus::Queued, RunStatus::Ok], array_map(static fn ($e) => $e->status, $events));
        self::assertSame(['ok'], $h->statuses($job));
        self::assertTrue($h->scheduler->holdsLease());
    }

    public function testSecondSchedulerDoesNothingWhileTheFirstHoldsTheLease(): void
    {
        $first = new SchedulerHarness();
        $second = new SchedulerHarness($first->db, '2026-10-07 08:00:00', 'zweiter');
        $job = $first->addJob();
        self::assertTrue($first->lease->acquire());

        self::assertSame([], $second->scheduler->tick(SchedulerHarness::never()));
        self::assertFalse($second->scheduler->holdsLease());
        self::assertSame([], $first->runs($job));
    }

    public function testNewLeaseHolderAbortsRunsOfTheOldProcessOnce(): void
    {
        $h = new SchedulerHarness();
        $job = $h->addJob(['next_run_at' => null]);
        $h->addRun($job, 'running', 'alter-prozess');

        $events = $h->scheduler->tick(SchedulerHarness::never());
        self::assertSame([RunStatus::Aborted], array_map(static fn ($e) => $e->status, $events));
        self::assertSame(['aborted'], $h->statuses($job));

        self::assertSame([], $h->scheduler->tick(SchedulerHarness::never()));
    }

    public function testStopRequestedMeansNoWorkAndShutdownReleasesTheLease(): void
    {
        $h = new SchedulerHarness();
        $job = $h->addJob();

        self::assertSame([], $h->scheduler->tick(static fn (): bool => true));
        self::assertSame([], $h->runs($job));

        $h->scheduler->tick(SchedulerHarness::never());
        $h->scheduler->shutdown();
        self::assertFalse($h->lease->isHeld());
        self::assertTrue((new SchedulerLease($h->db, $h->clock, 'nachfolger'))->acquire(), 'Nachfolger muss nicht auf den Ablauf warten');
    }
}
