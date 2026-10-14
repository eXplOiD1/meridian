<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Schedule\RunStatus;
use Meridian\Schedule\RunTrigger;

/**
 * Letzter abgeschlossener Lauf eines Jobs (ohne Testläufe) für Liste und Detail.
 */
final readonly class LastRun
{
    public function __construct(
        public int $id,
        public RunStatus $status,
        public RunTrigger $trigger,
        public ?string $finishedAt,
        public ?int $durationMs,
        public ?int $httpStatus,
        public ?int $automationId = null,
    ) {
    }
}
