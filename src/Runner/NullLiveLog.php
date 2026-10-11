<?php

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
