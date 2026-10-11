<?php

declare(strict_types=1);

namespace Meridian\Database;

/**
 * Das eine Zeitformat der Datenbank: UTC, ISO 8601 mit „+00:00“ (wie `format('c')`).
 * Weil alle Werte dieselbe Länge und denselben Versatz haben, vergleicht SQLite sie als Text korrekt
 * (`next_run_at <= :now`).
 */
final class Timestamp
{
    public const EPOCH = '1970-01-01T00:00:00+00:00';

    private function __construct()
    {
    }

    public static function format(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }

    /**
     * @throws \InvalidArgumentException wenn der Wert kein Zeitstempel im Datenbankformat ist
     */
    public static function parse(string $value): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        // Nur das exakte Format: was nicht unverändert zurückformatiert wird (anderer Versatz, „Z“,
        // übergelaufene Werte wie 25:00), wird abgelehnt.
        if ($time === false || self::format($time) !== $value) {
            throw new \InvalidArgumentException('Zeitstempel hat nicht das Datenbankformat (UTC, ISO 8601).');
        }

        return $time->setTimezone(new \DateTimeZone('UTC'));
    }
}
