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
}
