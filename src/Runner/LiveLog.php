<?php

declare(strict_types=1);

namespace Meridian\Runner;

/**
 * Live-Log eines Laufs (ADR 0004 E8, §5.1): nimmt **bereits maskierte** Stücke an, bündelt sie und begrenzt sie auf
 * {@see self::MAX_BYTES_PER_RUN} je Lauf. Wirft nie: ein Fehler beim Schreiben beendet höchstens das Live-Log, nie
 * den Lauf. Der Worker wartet nie auf Leser.
 */
interface LiveLog
{
    public const int MAX_BYTES_PER_RUN = 1048576;

    public function append(LiveStream $stream, #[\SensitiveParameter] string $maskedText): void;

    public function flush(): void;
}
