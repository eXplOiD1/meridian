<?php

declare(strict_types=1);

/**
 * Hilfsprozess für WorkerTest: versucht, alle wartenden Läufe zu übernehmen, und gibt die übernommenen
 * IDs zeilenweise aus. Aufruf: php claim_worker.php <db> <besitzer>
 */

use Meridian\Auth\SystemClock;
use Meridian\Database\Connection;
use Meridian\Runner\RunnerRegistry;
use Meridian\Runner\RunRequest;
use Meridian\Schedule\DbRunAuthorizer;
use Meridian\Schedule\SchedulerLease;
use Meridian\Schedule\Worker;
use Meridian\Security\PasswordHasher;
use Meridian\User\UserRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = Connection::open($argv[1] ?? '');
$clock = new SystemClock();
$lease = new SchedulerLease($db, $clock, $argv[2] ?? 'x');
$worker = new Worker($db, $clock, new \Meridian\Schedule\WorkerIdentity($argv[2] ?? 'x', \Meridian\Runner\JobType::Http), [\Meridian\Runner\JobType::Http], new RunnerRegistry(), new DbRunAuthorizer($db, new UserRepository($db, new PasswordHasher())));
$ids = [];
foreach ($db->fetchAll('SELECT id FROM runs ORDER BY id') as $row) {
    if (is_int($row['id'])) {
        $ids[] = $row['id'];
    }
}
foreach ($ids as $id) {
    if ($worker->claim($id) instanceof RunRequest) {
        echo $id, "\n";
    }
}
