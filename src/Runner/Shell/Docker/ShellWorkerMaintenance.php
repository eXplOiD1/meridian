<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell\Docker;

use Meridian\Database\Connection;
use Meridian\Runner\Shell\ExecFailed;

/**
 * Wartung im Shell-Worker-Kind (docs/decisions/0004 §5.2 Schritt 4, E10): beim Start und alle 60 s
 * `exec_ref`-Aufräumen ({@see ExecRefJanitor}); beim Start die Freigaben gegen `MERIDIAN_SHELL_CONTAINERS`
 * vergleichen; ist der Proxy nicht erreichbar, höchstens alle 5 min eine Warnung. Liefert nur feste Texte mit
 * Containernamen und Anzahlen — nie `exec_ref`, nie Meldungen aus Docker oder curl.
 */
final class ShellWorkerMaintenance
{
    public const INTERVAL_SECONDS = 60;
    public const WARN_EVERY_SECONDS = 300;
    public const NO_PROXY = 'Hinweis: MERIDIAN_DOCKER_PROXY ist nicht gesetzt: Shell-Jobs in Containern enden mit einer Fehlernotiz.';

    private ?float $lastWarning = null;

    public function __construct(
        private readonly Connection $db,
        private readonly ?DockerProxyClient $proxy,
        private readonly ?string $proxyAllowlist,
    ) {
    }

    /**
     * @return list<string>
     */
    public function run(bool $first): array
    {
        if ($this->proxy === null) {
            return $first ? [self::NO_PROXY] : [];
        }
        $lines = [];
        if ($first) {
            $rows = $this->db->fetchAll("SELECT DISTINCT name FROM shell_targets WHERE kind = 'docker' ORDER BY name");
            $names = array_column($rows, 'name');
            $lines = DockerStartupCheck::warnings($names, $this->proxyAllowlist);
            try {
                $this->proxy->apiPrefix();
            } catch (ExecFailed $e) {
                $lines[] = 'Warnung: ' . $e->getMessage();
                $this->lastWarning = microtime(true);
            }
        }
        $sweep = (new ExecRefJanitor($this->db, $this->proxy))->sweep();
        if ($sweep['killed'] > 0) {
            $lines[] = 'Verwaiste Container-Läufe beendet: ' . $sweep['killed'] . '.';
        }
        if ($sweep['pending'] > 0 && ($this->lastWarning === null || microtime(true) - $this->lastWarning >= self::WARN_EVERY_SECONDS)) {
            $lines[] = 'Warnung: ' . ExecFailed::PROXY_UNREACHABLE . ' (' . $sweep['pending'] . ' Lauf/Läufe warten auf Aufräumen)';
            $this->lastWarning = microtime(true);
        }

        return $lines;
    }
}
