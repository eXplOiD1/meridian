<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell\Docker;

use Meridian\Database\Connection;
use Meridian\Runner\Shell\ExecRef;

/**
 * Räumt `runs.exec_ref` beendeter Läufe ab (docs/decisions/0004 E5, §5.2 Schritt 4): nach einem Absturz läuft der
 * Befehl im Zielcontainer weiter. Für jeden beendeten Lauf mit `exec_ref`: `GET /exec/{id}/json`; läuft er noch,
 * Prozessgruppe mit SIGKILL beenden (Kill-Exec), dann `exec_ref = NULL`. Die Prüfung über die Exec-ID verhindert,
 * dass eine wiederverwendete PID getroffen wird. Ist der Proxy nicht erreichbar, bleibt die Zeile für den nächsten
 * Durchgang stehen. Gibt nie Inhalte von `exec_ref` aus (H2), nur Anzahlen.
 */
final class ExecRefJanitor
{
    public const BATCH = 50;

    public function __construct(private readonly Connection $db, private readonly DockerProxyClient $proxy)
    {
    }

    /**
     * @return array{cleared: int, killed: int, pending: int}
     */
    public function sweep(): array
    {
        $cleared = 0;
        $killed = 0;
        $pending = 0;
        $rows = $this->db->fetchAll(
            "SELECT id, exec_ref FROM runs WHERE exec_ref IS NOT NULL AND status NOT IN ('queued', 'running') ORDER BY id LIMIT :limit",
            ['limit' => self::BATCH],
        );
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (!is_int($id)) {
                continue;
            }
            $ref = self::parse($row['exec_ref'] ?? null);
            if ($ref !== null) {
                $running = DockerExecExecutor::execRunning($this->proxy, $ref->execId);
                if ($running === null) {
                    ++$pending;

                    continue;
                }
                if ($running && $ref->pgid !== null) {
                    if (!DockerExecExecutor::signal($this->proxy, $ref, 'KILL')) {
                        ++$pending;

                        continue;
                    }
                    ++$killed;
                }
                // Läuft er ohne bekannte Prozessgruppe (Absturz vor der Kennung), kann Meridian nichts tun: nur abräumen.
            }
            $cleared += $this->db->execute(
                "UPDATE runs SET exec_ref = NULL WHERE id = :id AND status NOT IN ('queued', 'running')",
                ['id' => $id],
            );
        }

        return ['cleared' => $cleared, 'killed' => $killed, 'pending' => $pending];
    }

    private static function parse(mixed $json): ?ExecRef
    {
        return is_string($json) ? ExecRef::fromJson($json) : null;
    }
}
