<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Runner\LiveLog;
use Meridian\Security\SecretMasker;

/**
 * Erzeugt das Live-Log eines Laufs (je Lauf neu, mit dem Masker dieses Laufs).
 */
interface LiveLogFactory
{
    public function forRun(int $runId, SecretMasker $masker): LiveLog;
}
