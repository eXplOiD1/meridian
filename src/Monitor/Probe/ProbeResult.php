<?php

declare(strict_types=1);

namespace Meridian\Monitor\Probe;

/**
 * Ergebnis einer aktiven Prüfung. Die Notiz ist immer ein fester Text; nie eine Antwort, ein Pfad oder ein Fehlertext des Ziels.
 */
final readonly class ProbeResult
{
    private function __construct(
        public bool $ok,
        public ?int $latencyMs,
        public string $note,
    ) {
    }

    public static function ok(int $latencyMs): self
    {
        return new self(true, $latencyMs, '');
    }

    public static function failed(string $note): self
    {
        return new self(false, null, $note);
    }
}
