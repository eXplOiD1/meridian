<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Database\Connection;
use Meridian\Runner\Http\UrlDisplay;
use Meridian\Security\SecretMasker;

/**
 * Schreibt gespeicherte Anzeige-URLs einer älteren {@see UrlDisplay}-Regel dauerhaft auf die aktuelle Version,
 * ohne zu entschlüsseln (nur strenger, nie lockerer). Läuft bei jedem `migrate` (Docker-Entrypoint, Installer)
 * nach den SQL-Migrationen; ein zweiter Lauf ändert nichts.
 *
 * Gelesen wird ohnehin verschärft ({@see HttpJobConfig::fromJson()}); dieser Schritt sorgt dafür, dass auch die
 * Klartextspalte `config_json` keine nach neuer Regel unsichtbaren Teile mehr enthält.
 */
final class DisplayUrlUpgrade
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return int Anzahl verschärfter Jobs
     */
    public function run(): int
    {
        return $this->db->immediate(function (Connection $db): int {
            $changed = 0;
            foreach ($db->fetchAll("SELECT id, config_json FROM jobs WHERE type = 'http' ORDER BY id") as $row) {
                $id = $row['id'] ?? null;
                $json = $row['config_json'] ?? null;
                if (!is_int($id) || !is_string($json) || !self::isOlder($json)) {
                    continue;
                }
                try {
                    $config = HttpJobConfig::fromJson($json);
                } catch (InvalidJobConfig) {
                    // Unlesbar: wird nie angezeigt; bleibt, bis die Anfrage neu eingegeben wird.
                    error_log((new SecretMasker())->mask('Meridian: Die Konfiguration von Job ' . $id . ' ist unlesbar; ihre Anzeige-URL wurde nicht auf die neue Regel gebracht (sie wird nicht angezeigt). Anfrage im Job neu eingeben und speichern.'));
                    continue;
                }
                $changed += $db->execute(
                    'UPDATE jobs SET config_json = :config WHERE id = :id AND config_json = :old',
                    ['config' => $config->toJson(), 'id' => $id, 'old' => $json],
                );
            }

            return $changed;
        });
    }

    private static function isOlder(string $json): bool
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!is_array($data) || !isset($data['http']) || !is_array($data['http'])) {
            return false;
        }
        $http = $data['http'];

        return isset($http['display_v']) && is_int($http['display_v']) && $http['display_v'] < UrlDisplay::VERSION;
    }
}
