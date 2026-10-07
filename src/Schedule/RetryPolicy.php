<?php

declare(strict_types=1);

namespace Meridian\Schedule;

/**
 * Wiederholen mit wachsendem Abstand: Basisabstand × 2^(Versuch − 1), höchstens eine Stunde.
 */
final class RetryPolicy
{
    public const MAX_DELAY_SECONDS = 3600;

    /** Obergrenze für retry_count, falls die Datenbank mehr enthält: keine endlose Wiederholung. */
    public const MAX_RETRIES = 10;

    private function __construct()
    {
    }

    /**
     * Abstand vor der nächsten Wiederholung, nachdem Versuch $failedAttempt fehlgeschlagen ist.
     */
    public static function delaySeconds(int $baseSeconds, int $failedAttempt): int
    {
        $base = max(1, min($baseSeconds, self::MAX_DELAY_SECONDS));
        // Ab 2^12 liegt jede Basis ≥ 1 s über der Obergrenze; so bleibt die Rechnung klein.
        $exponent = max(0, min($failedAttempt - 1, 12));

        return min($base << $exponent, self::MAX_DELAY_SECONDS);
    }

    /**
     * Darf nach Versuch $failedAttempt noch einmal versucht werden?
     */
    public static function allowsAnother(int $retryCount, int $failedAttempt): bool
    {
        return $failedAttempt <= max(0, min($retryCount, self::MAX_RETRIES));
    }
}
