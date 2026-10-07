<?php

declare(strict_types=1);

namespace Meridian\Http;

/**
 * Liest einen JSON-Anfragekörper als Objekt (assoziatives Array), mit Größen- und Tiefenlimit.
 */
final class JsonBody
{
    /**
     * @return array<mixed>|null null bei zu großem, ungültigem oder nicht-objektförmigem Inhalt
     */
    public static function object(string $body, int $maxBytes): ?array
    {
        if (strlen($body) > $maxBytes) {
            return null;
        }

        try {
            return self::onlyArray(json_decode($body, true, 4, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @return array<mixed>|null
     */
    private static function onlyArray(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
