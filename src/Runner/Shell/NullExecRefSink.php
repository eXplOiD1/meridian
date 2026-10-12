<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

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
