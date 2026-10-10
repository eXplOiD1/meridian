<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Startet ein Skript an einem Ausführungsort (docs/decisions/0004 §5.1): Docker-Exec über den Socket-Proxy
 * ({@see DockerExecExecutor}), später der Host-Agent (S11). {@see LocalProcessExecutor} nur im Host-Agenten und in
 * Tests, nie im Worker.
 */
interface Executor
{
    /**
     * @throws ExecFailed feste Meldung, nie Text aus Docker oder dem System
     */
    public function start(#[\SensitiveParameter] ExecSpec $spec): Execution;
}
