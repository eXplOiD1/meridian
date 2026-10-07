<?php

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Verschlüsselt Geheimnisse (API-Keys, URL-Parameter, Header) für die Datenbank.
 *
 * Format: "v1:" . base64(nonce . ciphertext), XSalsa20-Poly1305 über libsodium.
 * Ein manipulierter oder mit falschem Schlüssel verschlüsselter Wert wird
 * nie stillschweigend akzeptiert, sondern wirft eine Exception.
 */
final class SecretBox
{
    private const PREFIX = 'v1:';

    private string $key;

    public function __construct(#[\SensitiveParameter] string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Der Schlüssel muss genau 32 Byte lang sein.');
        }
        $this->key = $key;
    }

    public function __destruct()
    {
        // sodium_memzero löscht den Puffer an Ort und Stelle; die Kopie teilt sich ihn mit der Property.
        $key = $this->key;
        sodium_memzero($key);
    }

    /**
     * Verhindert, dass der Schlüssel in var_dump(), print_r() oder Fehlerausgaben landet.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => '••••'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('SecretBox darf nicht serialisiert werden.');
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return self::PREFIX . sodium_bin2base64($nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function decrypt(string $encoded): string
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new SecretException('Unbekanntes Format des verschlüsselten Werts.');
        }

        try {
            $raw = sodium_base642bin(substr($encoded, strlen(self::PREFIX)), SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            throw new SecretException('Verschlüsselter Wert ist beschädigt.');
        }

        if (strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new SecretException('Verschlüsselter Wert ist zu kurz.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if ($plain === false) {
            throw new SecretException('Entschlüsselung fehlgeschlagen: falscher Schlüssel oder manipulierter Wert.');
        }

        return $plain;
    }
}
