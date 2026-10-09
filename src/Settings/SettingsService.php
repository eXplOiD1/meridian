<?php

declare(strict_types=1);

namespace Meridian\Settings;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Auth\SystemClock;
use Meridian\Database\Connection;
use Meridian\Job\HttpJobConfig;
use Meridian\Job\InvalidJobConfig;
use Meridian\Runner\Http\UrlDisplay;
use Meridian\Security\SecretMasker;

/**
 * Ändert globale Einstellungen für API (`PUT /api/settings/{key}`) und CLI (`settings:set`) gleich
 * (docs/decisions/0003, E10, E3).
 *
 * In **einer** Transaktion (BEGIN IMMEDIATE, damit kein gleichzeitig gespeicherter Job dazwischen eine lockere
 * Anzeige-URL schreibt): alten Wert lesen → setzen oder auf den Standard zurücksetzen → bei `http.display_path =
 * hidden` die gespeicherten Anzeige-URLs aller HTTP-Jobs verschärfen → Audit `settings.changed` mit altem und
 * neuem Wert (Werte sind laut Allowlist nie geheim). Zurück auf `auto` lockert nichts nachträglich.
 *
 * Die Rechteprüfung (`settings.manage`) macht der Controller; an der Befehlszeile ist die Vertrauensgrenze der
 * Betriebssystem-Benutzer.
 */
final class SettingsService
{
    private const HTTP_JOBS = "SELECT id, config_json FROM jobs WHERE type = 'http' ORDER BY id";
    private const UPDATE_CONFIG = 'UPDATE jobs SET config_json = :config WHERE id = :id AND config_json = :old';

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
            if ($key === SettingKey::HttpDisplayPath && $new === DisplayPathMode::Hidden->value) {
                $tightened = $this->hideStoredDisplayUrls();
            }
            $this->audit->record($userId, 'settings.changed', self::describe($key, $old, $new, $valid === null, $tightened));

            return new SettingChange($settings->entry($key), $old, $new, $tightened);
        });
    }

    /**
     * Ersetzt Pfad und Query jeder gespeicherten `display_url` durch `/••••` bzw. `?••••` (nur verschärfen, ohne
     * Entschlüsseln). Unlesbare `config_json` bleibt unverändert: sie wird ohnehin nie angezeigt.
     *
     * @return int Zahl der geänderten Jobs
     */
    private function hideStoredDisplayUrls(): int
    {
        $changed = 0;
        foreach ($this->db->fetchAll(self::HTTP_JOBS) as $row) {
            $id = $row['id'] ?? null;
            $json = $row['config_json'] ?? null;
            if (!is_int($id) || !is_string($json)) {
                continue;
            }
            try {
                $config = HttpJobConfig::fromJson($json);
            } catch (InvalidJobConfig) {
                error_log((new SecretMasker())->mask('Meridian: Die Konfiguration von Job ' . $id . ' ist unlesbar; ihre Anzeige-URL wurde nicht verschärft (sie wird nicht angezeigt). Anfrage im Job neu eingeben und speichern.'));
                continue;
            }
            $hidden = UrlDisplay::hideStored($config->displayUrl, $config->target());
            if ($hidden === $config->displayUrl) {
                continue;
            }
            $changed += $this->db->execute(self::UPDATE_CONFIG, ['config' => $config->withDisplayUrl($hidden)->toJson(), 'id' => $id, 'old' => $json]);
        }

        return $changed;
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
