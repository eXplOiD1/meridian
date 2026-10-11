<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Schedule;

use Meridian\Database\Connection;
use Meridian\Schedule\DbRunAuthorizer;
use Meridian\Security\PasswordHasher;
use Meridian\Tests\Support\SchedulerHarness;
use Meridian\User\UserRepository;
use PHPUnit\Framework\TestCase;

final class DbRunAuthorizerTest extends TestCase
{
    public function testRolesAndCategoriesFromTheDatabase(): void
    {
        $h = new SchedulerHarness();
        $a = $h->addCategory('A');
        $b = $h->addCategory('B');
        $inA = $h->addJob(['category_id' => $a]);
        $inB = $h->addJob(['category_id' => $b]);
        $none = $h->addJob();
        $admin = $h->addUser('admin', 'Admin');
        $operator = $h->addUser('op', 'Operator');
        $operatorA = $h->addUser('op-a', 'Operator', ['A']);
        $observer = $h->addUser('beob', 'Beobachter');
        $authorizer = new DbRunAuthorizer($h->db, new UserRepository($h->db, new PasswordHasher()));

        $matrix = [];
        foreach (['admin' => $admin, 'op' => $operator, 'op-a' => $operatorA, 'beob' => $observer, 'unbekannt' => 999] as $name => $user) {
            foreach (['A' => $inA, 'B' => $inB, 'ohne' => $none, 'fehlt' => 999] as $job => $jobId) {
                $matrix[$name][$job] = $authorizer->mayStart($user, $jobId);
            }
        }

        self::assertSame([
            'admin' => ['A' => true, 'B' => true, 'ohne' => true, 'fehlt' => false],
            'op' => ['A' => true, 'B' => true, 'ohne' => true, 'fehlt' => false],
            'op-a' => ['A' => true, 'B' => false, 'ohne' => false, 'fehlt' => false],
            'beob' => ['A' => false, 'B' => false, 'ohne' => false, 'fehlt' => false],
            'unbekannt' => ['A' => false, 'B' => false, 'ohne' => false, 'fehlt' => false],
        ], $matrix);
    }

    public function testDatabaseErrorMeansNo(): void
    {
        // Leere Datenbank ohne Tabellen: jede Abfrage scheitert. Nie werfen, nie erlauben.
        $db = Connection::inMemory();
        $authorizer = new DbRunAuthorizer($db, new UserRepository($db, new PasswordHasher()));

        self::assertFalse($authorizer->mayStart(1, 1));
    }
}
