<?php

declare(strict_types=1);

namespace Meridian\Tests\Support;

use Meridian\Runner\Heartbeat;

/**
 * Herzschlag für Tests: zählt die Aufrufe und merkt sich die Zeitpunkte; ab `$aliveFor` Aufrufen liefert er false
 * (Lauf abgebrochen).
 */
final class CountingHeartbeat implements Heartbeat
{
    public int $beats = 0;

    /** @var list<float> */
    public array $times = [];

    public function __construct(public ?int $aliveFor = null)
    {
    }

    #[\Override]
    public function beat(): bool
    {
        ++$this->beats;
        $this->times[] = microtime(true);

        return $this->aliveFor === null || $this->beats <= $this->aliveFor;
    }

    public function stopReason(): ?\Meridian\Runner\StopReason
    {
        return $this->aliveFor !== null && $this->beats > $this->aliveFor
            ? \Meridian\Runner\StopReason::Cancelled
            : null;
    }

    /** Größter Abstand zwischen zwei Aufrufen in Sekunden. */
    public function maxGap(): float
    {
        $gap = 0.0;
        for ($i = 1, $n = count($this->times); $i < $n; ++$i) {
            $gap = max($gap, $this->times[$i] - $this->times[$i - 1]);
        }

        return $gap;
    }
}
