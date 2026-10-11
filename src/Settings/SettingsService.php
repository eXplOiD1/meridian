<?php

declare(strict_types=1);

namespace Meridian\Settings;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Auth\SystemClock;
use Meridian\Database\Connection;
use Meridian\Job\DisplayUrlUpgrade;

/**
 * Ändert globale Einstellungen für API (`PUT /api/settings/{key}`) und CLI (`settings:set`) gleich
 * (docs/decisions/0003, E10, E3).
 *
 * In **einer** Transaktion (BEGIN IMMEDIATE, damit kein gleichzeitig gespeicherter Job dazwischen eine lockere
 * Anzeige-URL schreibt): alten Wert lesen → setzen oder auf den Standard zurücksetzen → bei `http.display_path`
 * oder `http.display_host` die gespeicherten Anzeige-URLs aller HTTP-Jobs auf die neuen Einstellungen verschärfen
 * ({@see DisplayUrlUpgrade::tightenAll()}) → Audit `settings.changed` mit altem und
 * neuem Wert (Werte sind laut Allowlist nie geheim). Zurück auf `auto` lockert nichts nachträglich (das `target` der API folgt dagegen immer der aktuellen
 * Einstellung, es wird nicht gespeichert).
 *
 * Die Rechteprüfung (`settings.manage`) macht der Controller; an der Befehlszeile ist die Vertrauensgrenze der
 * Betriebssystem-Benutzer.
 */
final class SettingsService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AuditLog $audit,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * @return list<SettingEntry>
     */
    public function entries(): array
    {
        return $this->settings()->entries();
    }

    /**
     * @param mixed    $value  Rohwert aus JSON oder CLI; null setzt auf den Standard zurück
     * @param int|null $userId null an der Befehlszeile
     *
     * @throws InvalidSetting mit fester Meldung ohne den Wert, bevor etwas geschrieben wird
     */
    public function change(SettingKey $key, mixed $value, ?int $userId): SettingChange
    {
        $valid = $value === null ? null : Settings::validate($key, $value);

        return $this->db->immediate(function () use ($key, $valid, $userId): SettingChange {
            $settings = $this->settings();
            $old = $settings->value($key);
            $settings->set($key, $valid, $userId);
            $new = $settings->value($key);

            $tightened = 0;
            if ($key === SettingKey::HttpDisplayPath || $key === SettingKey::HttpDisplayHost) {
                // Mit den neuen Einstellungen verschärfen; `auto` lockert dabei nichts (tightenStored ist nur strenger).
                $tightened = DisplayUrlUpgrade::tightenAll($this->db, $settings->httpDisplay());
            }
            $this->audit->record($userId, 'settings.changed', self::describe($key, $old, $new, $valid === null, $tightened));

            return new SettingChange($settings->entry($key), $old, $new, $tightened);
        });
    }

    /** Audit-Ziel, z. B. `http.max_timeout_seconds: 300 → 600`. */
    private static function describe(SettingKey $key, int|string $old, int|string $new, bool $reset, int $tightened): string
    {
        return $key->value . ': ' . $old . ' → ' . $new
            . ($reset ? ' (Standard)' : '')
            . ($tightened > 0 ? '; Anzeige-URL bei ' . $tightened . ' Job(s) verschärft' : '');
    }

    private function settings(): Settings
    {
        return new Settings($this->db, $this->clock);
    }
}
