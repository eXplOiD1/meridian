<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Hält `runs.exec_ref` aktuell (docs/decisions/0004 §5.3 Schritt 4). Gehört dem Worker; der Runner selbst schreibt
 * nicht in die Datenbank. Wirft nie: ein Fehler kostet höchstens das Aufräumen nach einem Absturz.
 */
interface ExecRefSink
{
    public function record(int $runId, ExecRef $ref): void;

    public function clear(int $runId): void;
}
