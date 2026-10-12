<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Runner\Heartbeat;
use Meridian\Runner\StopReason;

/** Zustellungen sind kurz und gehören zu keinem Lauf: kein Herzschlag, nie ein Abbruch. */
final class NullHeartbeat implements Heartbeat
{
    /** @phpstan-pure */
    #[\Override]
    public function beat(): bool
    {
        return true;
    }

    #[\Override]
    public function stopReason(): ?StopReason
    {
        return null;
    }
}
