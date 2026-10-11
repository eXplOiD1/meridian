<?php

declare(strict_types=1);

/**
 * Hilfsprozess für LoginThrottleTest: reserviert genau einen Anmeldeversuch gegen eine Datenbankdatei und
 * schreibt 1 (darf geprüft werden) oder 0 (abgewiesen). Aufruf: php reserve_worker.php <db>
 */

use Meridian\Auth\LoginThrottle;
use Meridian\Auth\SystemClock;
use Meridian\Database\Connection;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$throttle = new LoginThrottle(Connection::open($argv[1] ?? ''), new SystemClock());
echo $throttle->reserve('victim', '10.0.0.1')->allowed ? '1' : '0';
