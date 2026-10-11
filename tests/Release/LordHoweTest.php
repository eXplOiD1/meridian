<?php
declare(strict_types=1);
namespace Meridian\Tests\Release;
use Meridian\Schedule\CronSchedule;
use PHPUnit\Framework\TestCase;
final class LordHoweTest extends TestCase {
 public function testMissingHalfHourIsShiftedAndRepeatedTimeRunsOnce(): void {
  $spring=CronSchedule::forJob('15 2 * * *','Australia/Lord_Howe');
  $first=$spring->nextAfter(new \DateTimeImmutable('2026-10-03T14:00:00Z'));
  self::assertSame('2026-10-04T02:45:00+11:00',$first->format('c'));
  self::assertSame('2026-10-05T02:15:00+11:00',$spring->nextAfter($first)->format('c'));
  $fall=CronSchedule::forJob('45 1 * * *','Australia/Lord_Howe');
  $first=$fall->nextAfter(new \DateTimeImmutable('2026-04-04T13:00:00Z'));
  self::assertSame('2026-04-05T01:45:00+11:00',$first->format('c'));
  self::assertSame('2026-04-06T01:45:00+10:30',$fall->nextAfter($first)->format('c'));
 }
}
