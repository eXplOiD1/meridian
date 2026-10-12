<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Status;

/**
 * Ein Eintrag einer Statusseite: ein Job oder eine Überwachung mit öffentlichem Anzeigenamen.
 */
final readonly class StatusItem
{
    public const JOB = 'job';
    public const MONITOR = 'monitor';

    public function __construct(
        public string $type,
        public int $refId,
        public string $label,
        /** interner Name des Jobs/der Überwachung (nur für die Verwaltung, nie öffentlich) */
        public ?string $refName = null,
    ) {
    }
}
