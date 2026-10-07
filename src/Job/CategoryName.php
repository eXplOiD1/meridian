<?php

declare(strict_types=1);

namespace Meridian\Job;

/**
 * Allowlist für Kategorienamen (docs/decisions/0003, O7): 1–64 Zeichen aus Buchstaben, Ziffern, Leerzeichen,
 * Unterstrich, Punkt und Bindestrich. Zusätzlich muss der Name mit einem Buchstaben oder einer Ziffer beginnen und
 * darf nicht mit einem Leerzeichen enden, damit zwei Kategorien nie gleich aussehen. Der Name landet in SQL-Parametern,
 * Audit-Zielen und der Oberfläche; ungültige Namen werden abgelehnt, nie zurechtgeschnitten.
 */
final class CategoryName
{
    public const MAX_LENGTH = 64;

    private function __construct()
    {
    }

    public static function isValid(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{0,63}$/D', $name) === 1 && !str_ends_with($name, ' ');
    }
}
