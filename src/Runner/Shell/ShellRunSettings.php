<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Globale Einstellungen eines Shell-Laufs, bei jedem Lauf frisch gelesen (nur lesen).
 */
interface ShellRunSettings
{
    /** `shell.max_timeout_seconds`, 1 bis 86400 s. */
    public function maxTimeoutSeconds(): int;
}
