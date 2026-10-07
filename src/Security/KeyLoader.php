<?php

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Lädt den 32-Byte-Hauptschlüssel.
 *
 * Reihenfolge: Datei aus MERIDIAN_KEY_FILE, sonst Umgebungsvariable MERIDIAN_KEY (base64).
 * Eine Schlüsseldatei, die für Gruppe oder andere lesbar ist, wird abgelehnt.
 */
final class KeyLoader
{
    /**
     * @param array<string, string> $env
     */
    public static function load(array $env): string
    {
        $file = $env['MERIDIAN_KEY_FILE'] ?? '';
        if ($file !== '') {
            return self::fromFile($file);
        }

        $encoded = $env['MERIDIAN_KEY'] ?? '';
        if ($encoded !== '') {
            return self::decode(trim($encoded));
        }

        throw new SecretException('Kein Schlüssel gesetzt. MERIDIAN_KEY_FILE oder MERIDIAN_KEY angeben (meridian key:generate).');
    }

    public static function fromFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new SecretException('Schlüsseldatei ist nicht lesbar.');
        }

        $perms = fileperms($path);
        if ($perms !== false && ($perms & 0o077) !== 0) {
            throw new SecretException('Schlüsseldatei ist für andere Benutzer lesbar. Rechte auf 0600 setzen.');
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new SecretException('Schlüsseldatei konnte nicht gelesen werden.');
        }

        return self::decode(trim($content));
    }

    public static function generate(): string
    {
        return sodium_bin2base64(sodium_crypto_secretbox_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    private static function decode(#[\SensitiveParameter] string $encoded): string
    {
        try {
            $key = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            throw new SecretException('Schlüssel ist kein gültiges base64.');
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new SecretException('Schlüssel muss 32 Byte lang sein.');
        }

        return $key;
    }
}
