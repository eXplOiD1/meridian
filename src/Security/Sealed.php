<?php

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Hält einen geheimen Wert so, dass ihn keine Darstellung des Objekts zeigt.
 *
 * `__debugInfo()` wirkt nur für var_dump/print_r/debug_zval_dump; var_export(), json_encode() (öffentliche
 * Felder), serialize() und `(array)` lesen die Felder eines Objekts direkt. Deshalb liegt der Wert nicht in
 * einem Feld, sondern in einer privaten statischen Tabelle, die keine Darstellung erreicht. Das Objekt selbst
 * hat keine Felder: var_export() zeigt `Sealed::__set_state(array())`, json_encode() `"[verborgen]"`,
 * serialize() und clone werfen.
 *
 * Wer einen Wert mit Geheimnis in einem Feld hält (URL, Header, Body, Schlüssel, bekannte Werte des Maskers),
 * legt ihn in ein `Sealed` (mer-security §11).
 *
 * @template-covariant T
 */
final class Sealed implements \JsonSerializable
{
    public const HIDDEN = '[verborgen]';

    /** @var array<int, mixed> Wert je Objekt-ID; der Eintrag lebt genau so lange wie das Objekt. */
    private static array $values = [];

    /**
     * @param T $value
     */
    public function __construct(#[\SensitiveParameter] mixed $value)
    {
        self::$values[spl_object_id($this)] = $value;
    }

    /**
     * @return T
     */
    public function open(): mixed
    {
        $id = spl_object_id($this);
        if (!array_key_exists($id, self::$values)) {
            throw new \LogicException('Der versiegelte Wert ist nicht mehr vorhanden.');
        }
        /** @var T $value */
        $value = self::$values[$id];

        return $value;
    }

    /**
     * Überschreibt einen Text-Wert an Ort und Stelle (z. B. Schlüssel), bevor er freigegeben wird.
     */
    public function wipe(): void
    {
        $id = spl_object_id($this);
        if (isset(self::$values[$id]) && is_string(self::$values[$id]) && self::$values[$id] !== '') {
            sodium_memzero(self::$values[$id]);
        }
        unset(self::$values[$id]);
    }

    public function __destruct()
    {
        unset(self::$values[spl_object_id($this)]);
    }

    /**
     * Eine Kopie hätte keinen Eintrag in der Tabelle; Objekte mit `Sealed`-Feldern teilen es beim Klonen.
     */
    public function __clone()
    {
        throw new \LogicException('Ein versiegelter Wert wird nicht kopiert.');
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => self::HIDDEN];
    }

    #[\Override]
    public function jsonSerialize(): string
    {
        return self::HIDDEN;
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Ein versiegelter Wert wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Ein versiegelter Wert wird nicht deserialisiert.');
    }
}
