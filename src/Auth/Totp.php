<?php

declare(strict_types=1);

namespace Meridian\Auth;

/**
 * Zeitbasierte Einmalpasswörter nach RFC 6238 (HMAC-SHA1, 30 Sekunden, 6 Stellen), wie sie
 * Authenticator-Apps erwarten. Ohne Fremdbibliothek, damit keine neue Abhängigkeit nötig ist.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    /** Toleranz in Zeitschritten vor und nach dem aktuellen (Uhrabweichung). */
    public const WINDOW = 1;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Neues Secret: 20 Zufallsbytes, als Base32 (so tippen oder scannen Benutzer es in die App).
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function stepAt(\DateTimeImmutable $time): int
    {
        return intdiv($time->getTimestamp(), self::PERIOD);
    }

    public static function code(#[\SensitiveParameter] string $base32Secret, int $step): string
    {
        $key = self::base32Decode($base32Secret);
        $hmac = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hmac[19]) & 0x0F;
        $value = ((ord($hmac[$offset]) & 0x7F) << 24)
            | (ord($hmac[$offset + 1]) << 16)
            | (ord($hmac[$offset + 2]) << 8)
            | ord($hmac[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Prüft einen Code im Fenster um den aktuellen Schritt.
     *
     * @param int|null $lastStep zuletzt akzeptierter Schritt; nur spätere Schritte gelten (kein Replay)
     *
     * @return int|null der passende Zeitschritt, null bei falschem oder bereits benutztem Code
     */
    public static function verify(#[\SensitiveParameter] string $base32Secret, string $code, \DateTimeImmutable $now, ?int $lastStep): ?int
    {
        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return null;
        }

        $current = self::stepAt($now);
        $matched = null;
        // Alle Schritte des Fensters prüfen, nicht beim ersten Treffer abbrechen (gleichbleibende Laufzeit).
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; ++$step) {
            if (hash_equals(self::code($base32Secret, $step), $code) && ($lastStep === null || $step > $lastStep)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    /**
     * Adresse, die Authenticator-Apps als QR-Code oder Link einlesen.
     */
    public static function uri(#[\SensitiveParameter] string $base32Secret, string $issuer, string $account): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $base32Secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32Encode(#[\SensitiveParameter] string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    public static function base32Decode(#[\SensitiveParameter] string $encoded): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($encoded, '='))) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                throw new \InvalidArgumentException('Ungültiges Base32-Zeichen.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr((int) bindec($byte));
            }
        }

        return $binary;
    }
}
