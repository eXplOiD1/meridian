<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner;

/**
 * Job-Typ (Spalte jobs.type).
 */
enum JobType: string
{
    case Http = 'http';
    case Shell = 'shell';
}
