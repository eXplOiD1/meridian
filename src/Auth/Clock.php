<?php

declare(strict_types=1);

namespace Meridian\Auth;

/**
 * Zeitquelle. Wird injiziert, damit Ablauf und Sperre in Tests ohne Warten prüfbar sind.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
