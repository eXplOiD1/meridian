<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Status;

final readonly class StatusPageRecord
{
    /**
     * @param list<StatusItem> $items
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $title,
        public ?int $categoryId,
        public ?string $categoryName,
        public bool $isEnabled,
        public array $items,
        public string $createdAt,
    ) {
    }
}
