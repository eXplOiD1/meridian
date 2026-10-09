<?php

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Vom Server erzeugtes Einmalpasswort für neue Benutzer und den Admin-Passwort-Reset (ADR 0005, E6).
 *
 * 20 Zeichen aus einem Alphabet ohne leicht verwechselbare Zeichen (`0`, `O`, `1`, `l`, `I`), gezogen aus
 * `random_bytes()` ohne Modulo-Verzerrung: 57 Zeichen → gut 116 Bit. Angezeigt und eingegeben in Vierergruppen
 * (`abcd-efgh-…`); die Gruppierung gehört zum Passwort, damit Kopieren und Abtippen dasselbe ergeben.
 *
 * Der Wert liegt nur in einem {@see Sealed}: keine Darstellung des Objekts zeigt ihn, (de)serialisieren und klonen
 * werfen. Lesen nur über {@see reveal()} — an genau zwei Stellen: die eine Antwort an den Admin und
 * `PasswordHasher::hash()`. Nie ins Audit, nie ins Log, nie in die Datenbank (nur der Argon2id-Hash).
 */
final class OneTimePassword implements \JsonSerializable
{
    public const ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const LENGTH = 20;
    public const GROUP = 4;
    /** Gültigkeit nach dem Erzeugen (B4): 7 Tage. */
    public const VALIDITY_SECONDS = 7 * 24 * 3600;

    /** @var Sealed<string> */
    private Sealed $value;

    private function __construct(#[\SensitiveParameter] string $value)
    {
        $this->value = new Sealed($value);
    }

    public static function generate(): self
    {
        $alphabet = self::ALPHABET;
        $size = strlen($alphabet);
        // Größtes Vielfaches der Alphabetgröße unter 256: Bytes darüber werden verworfen (keine Verzerrung).
        $limit = intdiv(256, $size) * $size;
        $chars = '';
        while (strlen($chars) < self::LENGTH) {
            $bytes = random_bytes(32);
            for ($i = 0; $i < 32; ++$i) {
                $byte = ord($bytes[$i]);
                if ($byte >= $limit) {
                    continue;
                }
                $chars .= $alphabet[$byte % $size];
                if (strlen($chars) === self::LENGTH) {
                    break;
                }
            }
        }

        return new self(implode('-', str_split($chars, self::GROUP)));
    }

    /**
     * Der Klartext. Nur für die eine Antwort an den Admin und zum Hashen.
     */
    public function reveal(): string
    {
        return $this->value->open();
    }

    /**
     * Ablauf ab einem Zeitpunkt (B4).
     */
    public static function expiresAt(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('+' . self::VALIDITY_SECONDS . ' seconds');
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => Sealed::HIDDEN];
    }

    #[\Override]
    public function jsonSerialize(): string
    {
        return Sealed::HIDDEN;
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Ein Einmalpasswort wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Ein Einmalpasswort wird nicht deserialisiert.');
    }

    public function __clone()
    {
        throw new \LogicException('Ein Einmalpasswort wird nicht kopiert.');
    }
}
