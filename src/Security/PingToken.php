<?php

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Ping-Tokens der Herzschlag-Überwachung (ADR 0011): 256 Bit Zufall, im Klartext nur beim Anlegen/Erneuern einmal sichtbar,
 * gespeichert wird nur der SHA-256-Hash. Eigenes Präfix, damit ein Ping-Token nie als API-Token (`mrd_`) durchgeht.
 */
final class PingToken
{
    public const PREFIX = 'mhb_';
    public const PATTERN = 'mhb_[A-Za-z0-9_-]{43}';

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
}
