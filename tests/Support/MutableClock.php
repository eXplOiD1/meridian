<?php

declare(strict_types=1);

namespace Meridian\Tests\Support;

use Meridian\Auth\Clock;

/**
 * Steuerbare Uhr für Tests: Ablauf und Sperre ohne Warten.
 */
final class MutableClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $start = '2026-10-07 08:00:00')
    {
        $this->now = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify('+' . $seconds . ' seconds');
    }
}
