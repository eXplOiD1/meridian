<?php

declare(strict_types=1);

/**
 * Hilfsprozess für LoginThrottleTest: zählt mehrfach Fehlversuche gegen eine Datenbankdatei,
 * damit mehrere Prozesse gleichzeitig zählen. Aufruf: php throttle_worker.php <db> <anzahl>
 */

use Meridian\Auth\LoginThrottle;
use Meridian\Auth\SystemClock;
use Meridian\Database\Connection;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$path = $argv[1] ?? '';
$count = (int) ($argv[2] ?? 0);
$throttle = new LoginThrottle(Connection::open($path), new SystemClock());
for ($i = 0; $i < $count; ++$i) {
    $throttle->recordFailure('alex', '10.0.0.1');
}
