<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Monitor\Probe;

use Meridian\Monitor\MonitorKind;

interface Prober
{
    /**
     * Prüft ein Ziel einmal. Wirft nie; jedes Problem steht als fester Text im Ergebnis.
     */
    public function probe(MonitorKind $kind, #[\SensitiveParameter] string $target, ?int $categoryId, string $expectedStatus): ProbeResult;
}
