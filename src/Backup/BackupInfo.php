<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Backup;

final readonly class BackupInfo
{
    public function __construct(
        public string $name,
        /** UTC, aus dem Dateinamen */
        public \DateTimeImmutable $createdAt,
        public int $bytes,
    ) {
    }
}
