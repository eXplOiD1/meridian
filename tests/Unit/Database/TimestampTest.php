<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Database;

use Meridian\Database\Timestamp;
use PHPUnit\Framework\TestCase;

final class TimestampTest extends TestCase
{
    public function testFormatIsUtcAndParseRoundTrips(): void
    {
        $berlin = new \DateTimeImmutable('2026-10-07 10:00:00', new \DateTimeZone('Europe/Berlin'));

        self::assertSame('2026-10-07T08:00:00+00:00', Timestamp::format($berlin));
        self::assertSame('2026-10-07T08:00:00+00:00', Timestamp::format(Timestamp::parse('2026-10-07T08:00:00+00:00')));
        self::assertSame('UTC', Timestamp::parse('2026-10-07T08:00:00+00:00')->getTimezone()->getName());
    }

    public function testOnlyTheExactFormatIsAccepted(): void
    {
        foreach ([
            '2026-10-07T08:00:00Z',
            '2026-10-07T10:00:00+02:00',
            '2026-10-07 08:00:00+00:00',
            '2026-10-07T25:00:00+00:00',
            '2026-02-30T08:00:00+00:00',
            '2026-10-07T08:00:00+00:00 ',
            '2026-10-07T08:00+00:00',
            '',
        ] as $value) {
            try {
                Timestamp::parse($value);
                self::fail('angenommen: ' . $value);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
