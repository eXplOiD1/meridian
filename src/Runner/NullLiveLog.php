<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner;

/**
 * Live-Log, das nichts speichert (Tests, Läufe ohne Live-Ansicht).
 */
final class NullLiveLog implements LiveLog
{
    #[\Override]
    public function append(LiveStream $stream, #[\SensitiveParameter] string $maskedText): void
    {
    }

    #[\Override]
    public function flush(): void
    {
    }
}
