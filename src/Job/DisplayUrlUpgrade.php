<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Database\Connection;
use Meridian\Runner\Http\UrlDisplay;
use Meridian\Security\SecretMasker;
use Meridian\Settings\HttpDisplay;
use Meridian\Settings\Settings;

/**
 * Bringt gespeicherte Anzeige-URLs dauerhaft auf die aktuelle {@see UrlDisplay}-Regel **und** die aktuellen
 * Anzeige-Einstellungen (`http.display_path`, `http.display_host`), ohne zu entschlüsseln (nur strenger, nie
 * lockerer). Läuft bei jedem `migrate` (Docker-Entrypoint, Installer) nach den SQL-Migrationen; ein zweiter Lauf
 * ändert nichts. {@see self::tightenAll()} benutzt auch {@see \Meridian\Settings\SettingsService} in derselben
 * Transaktion wie die Änderung einer Einstellung.
 *
 * Gelesen wird ohnehin verschärft ({@see HttpJobConfig::fromJson()} für die Regel, {@see \Meridian\Http\JobPresenter}
 * für die Einstellungen); dieser Schritt sorgt dafür, dass auch die Klartextspalte `config_json` nichts mehr enthält,
 * was nach Regel oder Einstellung unsichtbar ist. So gilt der Standard `hidden` (seit 09.10.2026) auch für
 * bestehende Installationen ohne gespeicherte Einstellung.
 */
final class DisplayUrlUpgrade
{
    private const HTTP_JOBS = "SELECT id, config_json FROM jobs WHERE type = 'http' ORDER BY id";
    private const UPDATE_CONFIG = 'UPDATE jobs SET config_json = :config WHERE id = :id AND config_json = :old';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return int Anzahl verschärfter Jobs
     */
    public function run(): int
    {
        return $this->db->immediate(static function (Connection $db): int {
            return self::tightenAll($db, (new Settings($db))->httpDisplay());
        });
    }

    /**
     * Verschärft die Anzeige-URL jedes HTTP-Jobs auf Regel und Einstellungen. Ohne eigene Transaktion: der Aufrufer
     * hält sie (BEGIN IMMEDIATE), damit kein gleichzeitig gespeicherter Job eine lockere Anzeige dazwischenschreibt.
     * Unlesbare `config_json` bleibt unverändert (sie wird nie angezeigt).
     *
     * @return int Anzahl geänderter Jobs
     */
    public static function tightenAll(Connection $db, HttpDisplay $display): int
    {
        $changed = 0;
        foreach ($db->fetchAll(self::HTTP_JOBS) as $row) {
            $id = $row['id'] ?? null;
            $json = $row['config_json'] ?? null;
            if (!is_int($id) || !is_string($json)) {
                continue;
            }
            try {
                // fromJson() hebt eine ältere Regel-Version schon an (upgradeStored).
                $config = HttpJobConfig::fromJson($json);
            } catch (InvalidJobConfig) {
                error_log((new SecretMasker())->mask('Meridian: Die Konfiguration von Job ' . $id . ' ist unlesbar; ihre Anzeige-URL wurde nicht verschärft (sie wird nicht angezeigt). Anfrage im Job neu eingeben und speichern.'));
                continue;
            }
            // Gleiche kanonische Form wie beim Speichern: unverändert → kein Schreiben (idempotent); eine ältere
            // Regel-Version (display_v) ergibt immer eine andere Form und wird so mitgeschrieben.
            $new = $config->withDisplayUrl(UrlDisplay::tightenStored($config->displayUrl, $config->target(), $display))->toJson();
            if ($new === $json) {
                continue;
            }
            $changed += $db->execute(self::UPDATE_CONFIG, ['config' => $new, 'id' => $id, 'old' => $json]);
        }

        return $changed;
    }
}
