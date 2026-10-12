<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Lädt den 32-Byte-Hauptschlüssel.
 *
 * Der Schlüssel kommt ausschließlich aus der Datei, auf die MERIDIAN_KEY_FILE zeigt (base64, 32 Byte).
 * Gruppenlesbare Dateien werden abgelehnt; Ausnahme sind geprüfte, root-eigene systemd-Credentials.
 */
final class KeyLoader
{
    /**
     * @param array<string, string> $env
     */
    public static function load(#[\SensitiveParameter] array $env): string
    {
        // Nur als Datei: Umgebungsvariablen sind in docker inspect und /proc/<pid>/environ sichtbar
        // und werden von Kindprozessen geerbt.
        $file = $env['MERIDIAN_KEY_FILE'] ?? '';
        if ($file === '') {
            throw new SecretException('Kein Schlüssel gesetzt. MERIDIAN_KEY_FILE auf eine Schlüsseldatei zeigen lassen (meridian key:generate).');
        }

        return self::readFile($file, self::isSystemdCredential($file, $env));
    }

    public static function fromFile(string $path): string
    {
        return self::readFile($path, false);
    }

    /** @param array<string, string> $env */
    private static function isSystemdCredential(string $path, array $env): bool
    {
        $directory = $env['CREDENTIALS_DIRECTORY'] ?? '';
        if (preg_match('#^/run/credentials/[A-Za-z0-9@_.:-]+$#D', $directory) !== 1
            || $path !== $directory . '/meridian_key' || realpath($path) !== $path
            || realpath($directory) !== $directory) {
            return false;
        }
        $dir = stat($directory);
        $file = stat($path);

        // systemd liefert root-eigene, schreibgeschützte Credentials mit einer ACL für den Dienstbenutzer.
        return $dir !== false && $file !== false
            && $dir['uid'] === 0 && $dir['gid'] === 0 && ($dir['mode'] & 0o777) === 0o550
            && $file['uid'] === 0 && $file['gid'] === 0 && ($file['mode'] & 0o777) === 0o440;
    }

    private static function readFile(string $path, bool $systemdCredential): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new SecretException('Schlüsseldatei ist nicht lesbar.');
        }

        $perms = fileperms($path);
        if (!$systemdCredential && $perms !== false && ($perms & 0o077) !== 0) {
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
