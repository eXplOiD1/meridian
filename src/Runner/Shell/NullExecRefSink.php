<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Für Ausführungsorte ohne Referenz (Host-Agent, Tests).
 */
final class NullExecRefSink implements ExecRefSink
{
    #[\Override]
    public function record(int $runId, ExecRef $ref): void
    {
    }

    #[\Override]
    public function clear(int $runId): void
    {
    }
}
