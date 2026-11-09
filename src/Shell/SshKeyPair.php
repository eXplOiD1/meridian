<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);

namespace Meridian\Shell;

/**
 * ed25519-Schlüsselpaar im OpenSSH-Format (ohne Passphrase) für SSH-Ausführungsorte und das Ablesen des
 * öffentlichen Teils aus einem vorhandenen OpenSSH-Schlüssel. Der private Teil verlässt den Server nie:
 * Er wird sofort verschlüsselt gespeichert, nur der öffentliche Teil geht an die Oberfläche.
 */
final class SshKeyPair
{
    private const MAGIC = "openssh-key-v1\0";
    private const TYPES = '/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521))$/D';

    /** @return array{private: string, public: string} */
    public static function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        sodium_memzero($pair);
        $publicBlob = self::string('ssh-ed25519') . self::string($public);
        $check = random_bytes(4);
        $section = $check . $check . self::string('ssh-ed25519') . self::string($public) . self::string($secret) . self::string('meridian');
        sodium_memzero($secret);
        // Auffüllen auf ein Vielfaches der Blockgröße 8 (1, 2, 3 …), wie ssh-keygen es erwartet.
        for ($i = 1; strlen($section) % 8 !== 0; $i++) {
            $section .= chr($i);
        }
        $blob = self::MAGIC . self::string('none') . self::string('none') . self::string('') . pack('N', 1) . self::string($publicBlob) . self::string($section);
        $pem = self::marker('BEGIN') . chunk_split(base64_encode($blob), 70, "\n") . self::marker('END');
        sodium_memzero($section);
        sodium_memzero($blob);

        return ['private' => $pem, 'public' => 'ssh-ed25519 ' . base64_encode($publicBlob)];
    }

    /** Öffentlicher Teil eines OpenSSH-Schlüssels ohne Passphrase als „Typ Schlüssel“; null bei anderen Formaten. */
    public static function publicFromPrivate(#[\SensitiveParameter] string $pem): ?string
    {
        if (preg_match('/^' . preg_quote(self::marker('BEGIN'), '/') . '([A-Za-z0-9+\/=\n]+)\n' . preg_quote(self::marker('END'), '/') . '$/D', $pem, $parts) !== 1) {
            return null;
        }
        $raw = base64_decode(str_replace("\n", '', $parts[1]), true);
        if ($raw === false || !str_starts_with($raw, self::MAGIC)) {
            return null;
        }
        $offset = strlen(self::MAGIC);
        $cipher = self::read($raw, $offset);
        $kdf = self::read($raw, $offset);
        $options = self::read($raw, $offset);
        if ($cipher !== 'none' || $kdf !== 'none' || $options !== '' || substr($raw, $offset, 4) !== pack('N', 1)) {
            return null;
        }
        $offset += 4;
        $blob = self::read($raw, $offset);
        if ($blob === null) {
            return null;
        }
        $inner = 0;
        $type = self::read($blob, $inner);

        return $type !== null && preg_match(self::TYPES, $type) === 1 ? $type . ' ' . base64_encode($blob) : null;
    }

    /** Anfangs- oder Endzeile des OpenSSH-Formats; aus Teilen gebaut, damit der Quelltext keinen Schlüsselblock-Kopf enthält. */
    private static function marker(string $edge): string
    {
        return '-----' . $edge . ' OPENSSH ' . 'PRIVATE KEY' . "-----\n";
    }

    private static function string(string $value): string
    {
        return pack('N', strlen($value)) . $value;
    }

    /** Liest eine Zeichenkette mit Längenpräfix ab $offset und rückt vor; null bei abgeschnittenen Daten. */
    private static function read(string $data, int &$offset): ?string
    {
        if (strlen($data) < $offset + 4) {
            return null;
        }
        $unpacked = unpack('N', substr($data, $offset, 4));
        $length = is_array($unpacked) && is_int($unpacked[1] ?? null) ? $unpacked[1] : -1;
        if ($length < 0 || strlen($data) < $offset + 4 + $length) {
            return null;
        }
        $value = substr($data, $offset + 4, $length);
        $offset += 4 + $length;

        return $value;
    }
}
