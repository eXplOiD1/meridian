<?php

declare(strict_types=1);

namespace Meridian\Security;

/**
 * API-Tokens: werden einmal im Klartext angezeigt, gespeichert wird nur der Hash.
 */
final class ApiToken
{
    private const PREFIX = 'mrd_';

    /**
     * @return array{plain: string, hash: string}
     */
    public static function issue(): array
    {
        $plain = self::PREFIX . sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        return ['plain' => $plain, 'hash' => self::hash($plain)];
    }

    public static function hash(#[\SensitiveParameter] string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function matches(#[\SensitiveParameter] string $plain, string $storedHash): bool
    {
        return str_starts_with($plain, self::PREFIX) && hash_equals($storedHash, self::hash($plain));
    }
}
