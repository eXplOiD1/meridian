<?php

declare(strict_types=1);

namespace Meridian\Auth;

final class SystemClock implements Clock
{
    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
