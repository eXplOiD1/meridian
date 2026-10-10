<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Runner\LiveLog;
use Meridian\Security\SecretMasker;

final class DbLiveLogFactory implements LiveLogFactory
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    #[\Override]
    public function forRun(int $runId, SecretMasker $masker): LiveLog
    {
        return new DbLiveLog($this->db, $this->clock, $runId, $masker);
    }
}
