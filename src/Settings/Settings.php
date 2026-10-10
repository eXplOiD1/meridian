<?php

declare(strict_types=1);

namespace Meridian\Settings;

use Meridian\Auth\Clock;
use Meridian\Auth\SystemClock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Security\SecretMasker;

/**
 * Globale Einstellungen aus `settings` (docs/decisions/0003, E10).
 *
 * Fehlt eine Zeile, gilt der Standard aus dem Code (nichts wird beim Start geseedet). Ist ein Wert in der
 * Datenbank ungültig, gilt ebenfalls der Standard und ein Fehlerlog nennt den Schlüssel, nie den Wert: ein
 * kaputter Eintrag macht nie lockerer als der Standard. Lesen ist überall erlaubt; Schreiben nur über
 * {@see self::set()} aus der Admin-API (`settings.manage`), nie aus Hintergrundprozessen.
 */
final class Settings
{
    public const DEFAULT_MAX_TIMEOUT_SECONDS = 300;
    public const MIN_TIMEOUT_SECONDS = 1;
    public const MAX_TIMEOUT_SECONDS = 3600;

    /** Shell-Jobs (ADR 0004 E12, O8): Standard-Maximum 1 h, höchstens 24 h. */
    public const SHELL_DEFAULT_MAX_TIMEOUT_SECONDS = 3600;
    public const SHELL_MAX_TIMEOUT_LIMIT_SECONDS = 86400;

    private const ENTRIES = 'SELECT s.key, s.value_json, s.updated_at, u.display_name FROM settings s LEFT JOIN users u ON u.id = s.updated_by';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /** Globales Maximum für das Zeitlimit eines HTTP-Jobs, 1 bis 3600 s. */
    public function maxTimeoutSeconds(): int
    {
        $value = $this->read(SettingKey::HttpMaxTimeout);

        return is_int($value) ? $value : Settings::DEFAULT_MAX_TIMEOUT_SECONDS;
    }

    /** Globales Maximum für das Zeitlimit eines Shell-Jobs, 1 bis 86400 s (Standard 3600). */
    public function shellMaxTimeoutSeconds(): int
    {
        $value = $this->read(SettingKey::ShellMaxTimeout);

        return is_int($value) ? $value : Settings::SHELL_DEFAULT_MAX_TIMEOUT_SECONDS;
    }

    public function responseStorage(): ResponseStorage
    {
        $value = $this->read(SettingKey::HttpResponseStorage);

        return is_string($value) ? (ResponseStorage::tryFrom($value) ?? ResponseStorage::default()) : ResponseStorage::default();
    }

    public function displayPath(): DisplayPathMode
    {
        $value = $this->read(SettingKey::HttpDisplayPath);

        return is_string($value) ? (DisplayPathMode::tryFrom($value) ?? DisplayPathMode::default()) : DisplayPathMode::default();
    }

    public function displayHost(): DisplayHostMode
    {
        $value = $this->read(SettingKey::HttpDisplayHost);

        return is_string($value) ? (DisplayHostMode::tryFrom($value) ?? DisplayHostMode::default()) : DisplayHostMode::default();
    }

    /**
     * `jobs.reveal_for_edit`: liefert `GET /api/jobs/{id}/source` gespeicherte Werte an Bearbeiter? Ein ungültiger
     * Wert in der Datenbank gilt als „aus“ (nie lockerer als der Standard).
     */
    public function revealForEdit(): bool
    {
        $value = $this->read(SettingKey::JobsRevealForEdit);

        return is_string($value) && RevealForEdit::tryFrom($value) === RevealForEdit::On;
    }

    /** Beide Anzeige-Einstellungen eines HTTP-Jobs, einmal je Anfrage zu lesen. */
    public function httpDisplay(): HttpDisplay
    {
        return new HttpDisplay($this->displayPath(), $this->displayHost());
    }

    /**
     * Gültiger Wert der Einstellung (Zeile oder Standard), für die Admin-Ansicht.
     */
    public function value(SettingKey $key): int|string
    {
        return $this->read($key);
    }

    /**
     * Prüft einen neuen Wert streng (Typ und Bereich, kein Umdeuten von „300“ zu 300).
     *
     * @throws InvalidSetting mit fester Meldung ohne den Wert
     */
    public static function validate(SettingKey $key, mixed $value): int|string
    {
        switch ($key) {
            case SettingKey::HttpMaxTimeout:
                if (!is_int($value) || $value < self::MIN_TIMEOUT_SECONDS || $value > self::MAX_TIMEOUT_SECONDS) {
                    throw new InvalidSetting('Das Maximum für das Zeitlimit muss eine ganze Zahl von 1 bis 3600 (Sekunden) sein.');
                }

                return $value;
            case SettingKey::ShellMaxTimeout:
                if (!is_int($value) || $value < self::MIN_TIMEOUT_SECONDS || $value > self::SHELL_MAX_TIMEOUT_LIMIT_SECONDS) {
                    throw new InvalidSetting('Das Maximum für das Zeitlimit von Shell-Jobs muss eine ganze Zahl von 1 bis 86400 (Sekunden) sein.');
                }

                return $value;
            case SettingKey::HttpResponseStorage:
                $storage = is_string($value) ? ResponseStorage::tryFrom($value) : null;
                if ($storage === null) {
                    throw new InvalidSetting('Antworten speichern: erlaubt sind „off“, „on“ und „never“.');
                }

                return $storage->value;
            case SettingKey::HttpDisplayPath:
                $mode = is_string($value) ? DisplayPathMode::tryFrom($value) : null;
                if ($mode === null) {
                    throw new InvalidSetting('Pfad in der URL-Anzeige: erlaubt sind „auto“ und „hidden“.');
                }

                return $mode->value;
            case SettingKey::HttpDisplayHost:
                $host = is_string($value) ? DisplayHostMode::tryFrom($value) : null;
                if ($host === null) {
                    throw new InvalidSetting('Host in der URL-Anzeige: erlaubt sind „auto“ und „hidden“.');
                }

                return $host->value;
            case SettingKey::JobsRevealForEdit:
                $reveal = is_string($value) ? RevealForEdit::tryFrom($value) : null;
                if ($reveal === null) {
                    throw new InvalidSetting('Gespeicherte Skripte und Links im Editor anzeigen: erlaubt sind „off“ und „on“.');
                }

                return $reveal->value;
        }
    }

    /**
     * Setzt eine Einstellung; `null` setzt sie auf den Standard zurück (Zeile löschen). Die Rechteprüfung
     * (`settings.manage`) und den Audit-Eintrag macht der Aufrufer ({@see SettingsService}). `$userId` ist `null`
     * an der Befehlszeile.
     *
     * @throws InvalidSetting
     */
    public function set(SettingKey $key, mixed $value, ?int $userId): void
    {
        if ($value === null) {
            $this->db->execute('DELETE FROM settings WHERE key = :key', ['key' => $key->value]);

            return;
        }

        $valid = self::validate($key, $value);
        $this->db->execute(
            'INSERT INTO settings (key, value_json, updated_by, updated_at) VALUES (:key, :value, :user, :at)
             ON CONFLICT (key) DO UPDATE SET value_json = excluded.value_json, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
            [
                'key' => $key->value,
                'value' => json_encode($valid, JSON_THROW_ON_ERROR),
                'user' => $userId,
                'at' => Timestamp::format($this->clock->now()),
            ],
        );
    }

    /**
     * Alle Schlüssel der Allowlist mit gültigem Wert, Standard und Herkunft, auch ohne Zeile (Admin-Ansicht).
     *
     * @return list<SettingEntry>
     */
    public function entries(): array
    {
        $rows = [];
        foreach ($this->db->fetchAll(self::ENTRIES) as $row) {
            $name = self::text($row, 'key');
            $key = $name === null ? null : SettingKey::tryFrom($name);
            if ($key !== null) {
                $rows[$key->value] = $row;
            }
        }

        $entries = [];
        foreach (SettingKey::cases() as $key) {
            $row = $rows[$key->value] ?? null;
            $value = $row === null ? null : $this->decode($key, $row['value_json'] ?? null);
            if ($row === null || $value === null) {
                $entries[] = new SettingEntry($key, $key->default(), false, null, null);
                continue;
            }
            $entries[] = new SettingEntry($key, $value, true, self::text($row, 'updated_at'), self::text($row, 'display_name'));
        }

        return $entries;
    }

    public function entry(SettingKey $key): SettingEntry
    {
        foreach ($this->entries() as $entry) {
            if ($entry->key === $key) {
                return $entry;
            }
        }

        // Unerreichbar: entries() liefert jeden Schlüssel der Allowlist.
        return new SettingEntry($key, $key->default(), false, null, null);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $column): ?string
    {
        return isset($row[$column]) && is_string($row[$column]) ? $row[$column] : null;
    }

    private function read(SettingKey $key): int|string
    {
        $row = $this->db->fetchOne('SELECT value_json FROM settings WHERE key = :key', ['key' => $key->value]);
        if ($row === null) {
            return $key->default();
        }

        return $this->decode($key, $row['value_json'] ?? null) ?? $key->default();
    }

    /**
     * Gespeicherten Wert streng prüfen; ungültig → `null` (der Aufrufer nimmt den Standard) und ein Fehlerlog, das
     * nur den Schlüssel nennt, nie den Wert.
     */
    private function decode(SettingKey $key, mixed $json): int|string|null
    {
        try {
            if (!is_string($json)) {
                throw new InvalidSetting('Wert fehlt.');
            }

            return self::validate($key, json_decode($json, false, 4, JSON_THROW_ON_ERROR));
        } catch (InvalidSetting | \JsonException) {
            // Nur der Schlüssel ins Log, nie der Wert; es gilt der Standard.
            error_log((new SecretMasker())->mask('Meridian: Die Einstellung ' . $key->value . ' ist in der Datenbank ungültig, der Standardwert gilt. Wert im Admin-Bereich neu setzen.'));

            return null;
        }
    }
}
