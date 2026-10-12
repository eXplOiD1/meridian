<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner;

/**
 * Herkunft eines Live-Log-Stücks (`run_log_chunks.stream`): Ausgabe, Fehlerausgabe, Meldung von Meridian selbst.
 */
enum LiveStream: string
{
    case Out = 'out';
    case Err = 'err';
    case Sys = 'sys';
}
