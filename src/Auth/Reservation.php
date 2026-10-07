<?php

declare(strict_types=1);

namespace Meridian\Auth;

/**
 * Ergebnis der Vorab-Reservierung eines Anmeldeversuchs (LoginThrottle::reserve).
 */
final readonly class Reservation
{
    private function __construct(
        public bool $allowed,
        public int $retryAfter,
        /** Dieser Versuch hat die Schwelle erreicht und die Sperre ausgelöst (er wird noch geprüft). */
        public bool $reachedLock,
    ) {
    }

    public static function allowed(bool $reachedLock): self
    {
        return new self(true, 0, $reachedLock);
    }

    public static function refused(int $retryAfter): self
    {
        return new self(false, max(1, $retryAfter), false);
    }
}
