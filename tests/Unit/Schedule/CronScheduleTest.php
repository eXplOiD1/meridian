<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Schedule;

use Meridian\Schedule\CronSchedule;
use PHPUnit\Framework\TestCase;

final class CronScheduleTest extends TestCase
{
    public function testNextRunsInLocalTime(): void
    {
        $schedule = new CronSchedule('0 3 * * *', new \DateTimeZone('Europe/Berlin'));
        $runs = $schedule->nextRuns(new \DateTimeImmutable('2026-10-06T13:26:00Z'), 2);

        self::assertSame('2026-10-07 03:00', $runs[0]->format('Y-m-d H:i'));
        self::assertSame('2026-10-08 03:00', $runs[1]->format('Y-m-d H:i'));
    }

    public function testDaylightSavingSwitchDoesNotSkipOrDuplicate(): void
    {
        // In der Nacht zum 25.10.2026 wird die Uhr von 03:00 auf 02:00 zurückgestellt.
        $schedule = new CronSchedule('30 1 * * *', new \DateTimeZone('Europe/Berlin'));
        $runs = $schedule->nextRuns(new \DateTimeImmutable('2026-10-24T12:00:00Z'), 3);

        self::assertSame(['2026-10-25 01:30', '2026-10-26 01:30', '2026-10-27 01:30'], array_map(
            static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d H:i'),
            $runs,
        ));
    }

    public function testWhitespaceIsNormalized(): void
    {
        self::assertSame('*/5 * * * *', (new CronSchedule("  */5  *\t* * * ", new \DateTimeZone('UTC')))->expression());
    }

    public function testInvalidExpressionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CronSchedule('61 * * * *', new \DateTimeZone('UTC'));
    }

    public function testSkippedHourInMarchRunsExactlyOnce(): void
    {
        // 29.03.2026: 02:00 → 03:00 (Sommerzeit). 02:30 gibt es nicht, der Lauf kommt einmal um 03:30.
        $schedule = new CronSchedule('30 2 * * *', new \DateTimeZone('Europe/Berlin'));

        self::assertSame(
            ['2026-03-28T01:30:00+00:00', '2026-03-29T01:30:00+00:00', '2026-03-30T00:30:00+00:00'],
            self::utc($schedule->nextRuns(new \DateTimeImmutable('2026-03-27T12:00:00Z'), 3)),
        );
        self::assertSame('2026-03-29 03:30 CEST', $schedule->nextAfter(new \DateTimeImmutable('2026-03-28T12:00:00Z'))->format('Y-m-d H:i T'));
    }

    public function testRepeatedHourInOctoberDoesNotRunAFixedTimeJobTwice(): void
    {
        // 25.10.2026: 03:00 → 02:00. 02:30 gibt es zweimal (00:30Z und 01:30Z), gelaufen wird nur einmal.
        $schedule = new CronSchedule('30 2 * * *', new \DateTimeZone('Europe/Berlin'));

        self::assertSame(
            ['2026-10-25T00:30:00+00:00', '2026-10-26T01:30:00+00:00'],
            self::utc($schedule->nextRuns(new \DateTimeImmutable('2026-10-24T12:00:00Z'), 2)),
        );
        // Auch wer genau nach dem ersten Durchgang fragt, bekommt den nächsten Tag.
        self::assertSame('2026-10-26T01:30:00+00:00', self::utc([$schedule->nextAfter(new \DateTimeImmutable('2026-10-25T00:30:00Z'))])[0]);
    }

    public function testIntervalJobsFollowRealTimeThroughTheRepeatedHour(): void
    {
        // Stündliche Jobs laufen jede echte Stunde, also in beiden Durchgängen von 02:00.
        $schedule = new CronSchedule('0 * * * *', new \DateTimeZone('Europe/Berlin'));

        self::assertSame(
            ['2026-10-24T23:00:00+00:00', '2026-10-25T00:00:00+00:00', '2026-10-25T01:00:00+00:00', '2026-10-25T02:00:00+00:00'],
            self::utc($schedule->nextRuns(new \DateTimeImmutable('2026-10-24T22:15:00Z'), 4)),
        );
    }

    public function testLibraryDefectsAtTheOctoberChangeAreFiltered(): void
    {
        // Die Bibliothek liefert hier 01:00Z (= 02:00 MEZ), was zu keinem der Ausdrücke passt.
        $berlin = new \DateTimeZone('Europe/Berlin');
        $from = new \DateTimeImmutable('2026-10-24T20:00:00Z');

        self::assertSame(['2026-10-25T02:00:00+00:00', '2026-10-26T02:00:00+00:00'], self::utc((new CronSchedule('0 3 * * *', $berlin))->nextRuns($from, 2)));
        self::assertSame(['2026-10-25T02:00:00+00:00', '2026-10-25T02:30:00+00:00'], self::utc((new CronSchedule('*/30 3 * * *', $berlin))->nextRuns($from, 2)));
        self::assertSame(['2026-10-24T22:00:00+00:00', '2026-10-25T02:00:00+00:00'], self::utc((new CronSchedule('0 */3 * * *', $berlin))->nextRuns($from, 2)));
    }

    public function testNextAfterIsStrictlyLater(): void
    {
        $schedule = new CronSchedule('*/10 * * * *', new \DateTimeZone('UTC'));

        self::assertSame('2026-10-07T08:10:00+00:00', self::utc([$schedule->nextAfter(new \DateTimeImmutable('2026-10-07T08:00:00Z'))])[0]);
        self::assertSame('2026-10-07T08:10:00+00:00', self::utc([$schedule->nextAfter(new \DateTimeImmutable('2026-10-07T08:09:59Z'))])[0]);
    }

    public function testJobTimezoneMustBeAnIdentifier(): void
    {
        self::assertSame('Europe/Berlin', CronSchedule::forJob('0 3 * * *', 'Europe/Berlin')->timezone->getName());
        foreach (['CEST', '+02:00', 'Mars/Olympus', ''] as $tz) {
            try {
                CronSchedule::forJob('0 3 * * *', $tz);
                self::fail('Zeitzone angenommen: ' . $tz);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Zeitzone', $e->getMessage());
            }
        }
    }

    public function testImpossibleDateHasNoNextRun(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CronSchedule('0 0 30 2 *', new \DateTimeZone('UTC')))->nextAfter(new \DateTimeImmutable('2026-10-07T08:00:00Z'));
    }

    /**
     * @param list<\DateTimeImmutable> $times
     *
     * @return list<string>
     */
    private static function utc(array $times): array
    {
        return array_map(static fn (\DateTimeImmutable $d): string => $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'), $times);
    }
}
