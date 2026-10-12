<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Lädt einen Job für den HTTP-Runner, bei jedem Lauf frisch. Liest nur, schreibt nie.
 */
interface HttpJobSource
{
    /** null = Job gibt es nicht (mehr). */
    public function load(int $jobId): ?StoredHttpJob;
}
