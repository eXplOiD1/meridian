<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Database\Connection;

/**
 * {@see ExecRefSink} in `runs.exec_ref`. Schreibt nur in laufende Läufe; gelöscht wird, sobald der Befehl sicher
 * beendet ist. Was nach einem Absturz stehen bleibt, räumt der Shell-Worker beim Start und alle 60 s ab (ADR 0004 E5).
 */
final class DbExecRefSink implements ExecRefSink
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[\Override]
    public function record(int $runId, ExecRef $ref): void
    {
        try {
            $this->db->execute(
                "UPDATE runs SET exec_ref = :ref WHERE id = :id AND status = 'running'",
                ['ref' => $ref->toJson(), 'id' => $runId],
            );
        } catch (\Throwable) {
            // Wirft nie (siehe Schnittstelle).
        }
    }

    #[\Override]
    public function clear(int $runId): void
    {
        try {
            $this->db->execute('UPDATE runs SET exec_ref = NULL WHERE id = :id', ['id' => $runId]);
        } catch (\Throwable) {
            // Bleibt stehen: ExecRefJanitor prüft über die Exec-ID und räumt ab.
        }
    }
}
