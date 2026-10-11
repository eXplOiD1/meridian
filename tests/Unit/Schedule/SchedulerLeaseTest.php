<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Schedule;

use Meridian\Database\Connection;
use Meridian\Schedule\SchedulerLease;
use Meridian\Tests\Support\MutableClock;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\TestCase;

/**
 * Zwei Scheduler-Instanzen mit eigener Verbindung auf dieselbe Datenbankdatei: immer nur einer hält die Sperre.
 */
final class SchedulerLeaseTest extends TestCase
{
    private string $file = '';
    private ?MutableClock $clockInstance = null;

    protected function tearDown(): void
    {
        if ($this->file !== '') {
            SchedulerHarness::removeDatabase($this->file);
        }
    }

    private function clock(): MutableClock
    {
        return $this->clockInstance ??= new MutableClock();
    }

    /**
     * Eine Scheduler-Instanz mit eigener Verbindung auf dieselbe Datei.
     */
    private function instance(string $owner): SchedulerLease
    {
        if ($this->file === '') {
            $this->file = SchedulerHarness::tempDatabase();
            SchedulerHarness::migrate(Connection::open($this->file));
        }

        return new SchedulerLease(Connection::open($this->file), $this->clock(), $owner);
    }

    public function testOnlyOneInstanceHoldsTheLease(): void
    {
        $a = $this->instance('instanz-a');
        $b = $this->instance('instanz-b');
        self::assertTrue($a->acquire());
        self::assertFalse($b->acquire());
        self::assertTrue($a->isHeld());
        self::assertFalse($b->isHeld());

        // Verlängern: A bleibt Besitzer, auch kurz vor Ablauf.
        $this->clock()->advance(SchedulerLease::TTL_SECONDS - 1);
        self::assertTrue($a->acquire());
        $this->clock()->advance(SchedulerLease::TTL_SECONDS - 1);
        self::assertFalse($b->acquire(), 'verlängerte Sperre ist nicht abgelaufen');
        self::assertTrue($a->isHeld());
    }

    public function testExpiredLeaseIsTakenOverAndTheOldOwnerLosesIt(): void
    {
        $a = $this->instance('instanz-a');
        $b = $this->instance('instanz-b');
        self::assertTrue($a->acquire());
        $this->clock()->advance(SchedulerLease::TTL_SECONDS);

        self::assertFalse($a->isHeld(), 'abgelaufen heißt nicht mehr gehalten');
        self::assertTrue($b->acquire());
        self::assertFalse($a->acquire(), 'der alte Besitzer bekommt sie nicht zurück, solange B verlängert');
        self::assertFalse($a->isHeld());
        self::assertTrue($b->isHeld());
    }

    public function testReleaseHandsOverImmediatelyAndOnlyTheOwnerCanRelease(): void
    {
        $a = $this->instance('instanz-a');
        $b = $this->instance('instanz-b');
        self::assertTrue($a->acquire());
        $b->release();
        self::assertFalse($b->acquire(), 'fremdes Freigeben wirkt nicht');

        $a->release();
        self::assertFalse($a->isHeld());
        self::assertTrue($b->acquire());
    }

    public function testOwnerIdsAreUniqueAndEmptyOwnerIsRejected(): void
    {
        self::assertNotSame(SchedulerLease::newOwnerId(), SchedulerLease::newOwnerId());

        $this->expectException(\InvalidArgumentException::class);
        $this->instance('');
    }
}
