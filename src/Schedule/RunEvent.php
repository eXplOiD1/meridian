<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Schedule;

/**
 * Was ein Takt mit einem Lauf gemacht hat. Enthält bewusst nur IDs und Status, nie Payload oder Ausgabe:
 * daraus entsteht die Ausgabe des Dienstes.
 */
final readonly class RunEvent
{
    public function __construct(
        public int $jobId,
        public int $runId,
        public RunStatus $status,
    ) {
    }
}
