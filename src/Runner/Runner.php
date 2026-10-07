<?php

declare(strict_types=1);

namespace Meridian\Runner;

/**
 * Führt einen Lauf aus (HTTP ab Phase 3, Shell ab Phase 4). Ein Runner schreibt nichts in die Datenbank;
 * den Laufzustand speichert der Worker.
 */
interface Runner
{
    public function run(RunRequest $request): RunResult;
}
