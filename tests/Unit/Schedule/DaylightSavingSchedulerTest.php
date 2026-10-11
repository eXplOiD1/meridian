<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Schedule;

use Meridian\Runner\JobType;
use Meridian\Runner\RunResult;
use Meridian\Tests\Support\FakeRunner;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\TestCase;

/**
 * Pflicht-Tests Sommerzeit mit festen Daten (Europe/Berlin): der ganze Takt (Planen + Ausführen) läuft
 * minütlich durch die Umstellungsnacht.
 */
final class DaylightSavingSchedulerTest extends TestCase
{
    public function testRunInTheSkippedHourOfMarchHappensExactlyOnce(): void
    {
        // 29.03.2026, 02:00 MEZ → 03:00 MESZ: 02:30 gibt es nicht.
        $scheduled = $this->simulate('30 2 * * *', '2026-03-28 22:00:00', 6 * 60);

        self::assertSame(['2026-03-29T01:30:00+00:00'], $scheduled, 'genau ein Lauf, um 03:30 MESZ');
    }

    public function testRunInTheRepeatedHourOfOctoberHappensOnlyOnce(): void
    {
        // 25.10.2026, 03:00 MESZ → 02:00 MEZ: 02:30 gibt es zweimal (00:30Z und 01:30Z).
        $scheduled = $this->simulate('30 2 * * *', '2026-10-24 22:00:00', 6 * 60);

        self::assertSame(['2026-10-25T00:30:00+00:00'], $scheduled, 'nicht zweimal');
    }

    public function testDailyRunsAroundTheChangeKeepLocalTime(): void
    {
        $scheduled = $this->simulate('0 3 * * *', '2026-10-24 00:00:00', 3 * 24 * 60, 5);

        self::assertSame(['2026-10-24T01:00:00+00:00', '2026-10-25T02:00:00+00:00', '2026-10-26T02:00:00+00:00'], $scheduled, 'immer 03:00 Ortszeit');
    }

    /**
     * @return list<string> geplante Zeitpunkte der ausgeführten Läufe (UTC)
     */
    private function simulate(string $cron, string $start, int $minutes, int $step = 1): array
    {
        $h = new SchedulerHarness(null, $start);
        $runner = new FakeRunner(RunResult::ok());
        $h->runners->register(JobType::Http, $runner);
        $job = $h->addJob(['cron' => $cron, 'timezone' => 'Europe/Berlin', 'next_run_at' => null]);
        $h->planner->reschedule($job);

        for ($i = 0; $i < $minutes; $i += $step) {
            $h->scheduler->tick(SchedulerHarness::never());
            $h->clock->advance(60 * $step);
        }

        $runs = $h->runs($job);
        self::assertSame(array_fill(0, count($runs), 'ok'), array_column($runs, 'status'));
        self::assertCount(count($runs), $runner->requests);

        return array_values(array_filter(array_column($runs, 'scheduled_for'), is_string(...)));
    }
}
