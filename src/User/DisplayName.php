<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Http\ValidationFailed;

/**
 * Anzeigename (ADR 0005, §3.2, E10): String, 1–64 Zeichen, keine Steuer-/Formatzeichen, nicht mit Leerraum beginnend
 * oder endend. Ablehnen, nie zurechtschneiden; die Meldung enthält den Wert nicht.
 */
final class DisplayName
{
    public const MAX = 64;
    public const MESSAGE = 'Anzeigename: 1 bis 64 Zeichen, ohne Steuerzeichen und ohne Leerzeichen am Anfang oder Ende.';

    private function __construct()
    {
    }

    /**
     * @throws ValidationFailed
     */
    public static function validate(mixed $value, string $field = 'display_name'): string
    {
        if (!is_string($value) || !self::isValid($value)) {
            throw ValidationFailed::field($field, self::MESSAGE);
        }

        return $value;
    }

    public static function isValid(string $value): bool
    {
        // preg_match() liefert bei ungültigem UTF-8 false: dann ist es ungültig.
        if ($value === '' || mb_strlen($value, 'UTF-8') > self::MAX || preg_match('/^[\p{Z}\s]|[\p{Z}\s]$|[\p{Cc}\p{Cf}\p{Cs}\p{Co}]/u', $value) !== 0) {
            return false;
        }

        return mb_check_encoding($value, 'UTF-8');
    }
}
