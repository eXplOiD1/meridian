<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Database;

use Meridian\Database\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    public function testAcceptsSqlite335AndNewer(): void
    {
        Connection::requireSqliteVersion('3.35.0');
        Connection::requireSqliteVersion('3.45.1');
        Connection::requireSqliteVersion('4.0.0');

        $this->addToAssertionCount(1);
    }

    public function testRejectsOlderOrUnknownVersionsWithAClearMessage(): void
    {
        foreach (['3.34.1', '3.7.17', ''] as $version) {
            try {
                Connection::requireSqliteVersion($version);
                self::fail('Version ' . $version . ' hätte abgelehnt werden müssen.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('SQLite 3.35', $e->getMessage());
            }
        }
    }

    public function testTheRunningSqliteIsNewEnough(): void
    {
        self::assertInstanceOf(Connection::class, Connection::inMemory());
    }

    public function testParametersAreBoundWithTheirType(): void
    {
        $db = Connection::inMemory();

        self::assertSame(
            ['i' => 1, 's' => 0, 'n' => 1, 'b' => 1, 't' => 'integer', 'f' => 'text'],
            $db->fetchOne(
                'SELECT :one = 1 AS i, :text = 1 AS s, :none IS NULL AS n, :yes = 1 AS b, typeof(:one) AS t, typeof(:text) AS f',
                ['one' => 1, 'text' => '1', 'none' => null, 'yes' => true],
            ),
        );
        // Auch mit führendem Doppelpunkt im Namen.
        self::assertSame(['v' => 7], $db->fetchOne('SELECT :x AS v', [':x' => 7]));
    }
}
