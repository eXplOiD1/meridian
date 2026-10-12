<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Schedule;

/**
 * Auslöser eines Laufs (Spalte runs.trigger).
 */
enum RunTrigger: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Retry = 'retry';
    case Test = 'test';
}
