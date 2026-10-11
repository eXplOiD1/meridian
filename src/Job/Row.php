<?php

declare(strict_types=1);

namespace Meridian\Job;

/**
 * Typgeprüfter Zugriff auf Datenbankzeilen (PHPStan max verlangt es; ein unerwarteter Typ ist ein Fehler im
 * Datenbestand und wird laut, nie still zu „leer“).
 */
final class Row
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException('Datenbankspalte ' . $column . ' hat nicht den erwarteten Typ (Zahl).');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function intOrNull(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new \UnexpectedValueException('Datenbankspalte ' . $column . ' hat nicht den erwarteten Typ (Zahl oder leer).');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Datenbankspalte ' . $column . ' hat nicht den erwarteten Typ (Text).');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function stringOrNull(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \UnexpectedValueException('Datenbankspalte ' . $column . ' hat nicht den erwarteten Typ (Text oder leer).');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function bool(array $row, string $column): bool
    {
        return self::int($row, $column) === 1;
    }
}
