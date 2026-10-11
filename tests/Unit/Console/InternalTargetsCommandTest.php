<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Console;

use Meridian\Auth\AuditLog;
use Meridian\Console\InternalTargetsCommand;
use Meridian\Database\Connection;
use Meridian\Network\InternalTargetStore;
use Meridian\Security\SecretMasker;
use Meridian\Tests\Support\MutableClock;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InternalTargetsCommandTest extends TestCase
{
    private ?Connection $connection = null;

    private function db(): Connection
    {
        if ($this->connection === null) {
            $this->connection = Connection::inMemory();
            SchedulerHarness::migrate($this->connection);
            $this->connection->execute("INSERT INTO categories (id, name) VALUES (1, 'NAS')");
        }

        return $this->connection;
    }

    /**
     * @param array<string, string> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $clock = new MutableClock();
        $tester = new CommandTester(new InternalTargetsCommand(new InternalTargetStore($this->db(), $clock), new AuditLog($this->db(), $clock, new SecretMasker())));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @return list<string>
     */
    private function audit(): array
    {
        $out = [];
        foreach ($this->db()->fetchAll("SELECT action, target, user_id FROM audit_log WHERE action LIKE 'network.%' ORDER BY id") as $row) {
            self::assertNull($row['user_id'], 'Befehlszeile: ohne Benutzer');
            $out[] = (is_string($row['action']) ? $row['action'] : '') . ' ' . (is_string($row['target']) ? $row['target'] : '');
        }

        return $out;
    }

    public function testAddListRemove(): void
    {
        self::assertStringContainsString('Keine Freigaben', $this->runCommand(['action' => 'list'])->getDisplay(true));

        $add = $this->runCommand(['action' => 'add', 'first' => 'cidr', 'second' => '192.168.10.0/24', '--port' => '8080', '--category' => 'NAS', '--note' => 'Keller']);
        self::assertSame(Command::SUCCESS, $add->getStatusCode());
        self::assertStringContainsString('Freigabe angelegt: #1 cidr 192.168.10.0/24:8080 (Kategorie NAS) – Keller', $add->getDisplay(true));

        self::assertSame(Command::SUCCESS, $this->runCommand(['action' => 'add', 'first' => 'host', 'second' => 'nextcloud'])->getStatusCode());
        $list = $this->runCommand(['action' => 'list'])->getDisplay(true);
        self::assertStringContainsString('#1 cidr 192.168.10.0/24:8080 (Kategorie NAS)', $list);
        self::assertStringContainsString('#2 host nextcloud:* (global)', $list);

        $remove = $this->runCommand(['action' => 'remove', 'first' => '2']);
        self::assertSame(Command::SUCCESS, $remove->getStatusCode());
        self::assertSame(Command::INVALID, $this->runCommand(['action' => 'remove', 'first' => '2'])->getStatusCode());

        self::assertSame([
            'network.internal_target_added cidr 192.168.10.0/24:8080 (Kategorie NAS)',
            'network.internal_target_added host nextcloud:* (global)',
            'network.internal_target_removed host nextcloud:* (global)',
        ], $this->audit());
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalid(): iterable
    {
        yield 'Metadaten' => [['action' => 'add', 'first' => 'cidr', 'second' => '169.254.0.0/16'], 'nicht vollständig in einem privaten Netz'];
        yield 'Loopback ohne Port' => [['action' => 'add', 'first' => 'cidr', 'second' => '127.0.0.1/32'], 'nur einzeln mit einem bestimmten Port'];
        yield 'Hostbits' => [['action' => 'add', 'first' => 'cidr', 'second' => '192.168.1.5/24'], 'Hostbits'];
        yield 'localhost' => [['action' => 'add', 'first' => 'host', 'second' => 'localhost'], 'nie freigebbar'];
        yield 'Kategorie unbekannt' => [['action' => 'add', 'first' => 'cidr', 'second' => '10.0.0.0/8', '--category' => 'Gibtsnicht'], 'Kategorie unbekannt'];
        yield 'Port' => [['action' => 'add', 'first' => 'cidr', 'second' => '10.0.0.0/8', '--port' => '08080'], 'Port muss'];
        yield 'Port zu groß' => [['action' => 'add', 'first' => 'cidr', 'second' => '10.0.0.0/8', '--port' => '70000'], 'Ungültiger Port'];
        yield 'Art' => [['action' => 'add', 'first' => 'url', 'second' => '10.0.0.0/8'], 'Unbekannte Art'];
        yield 'Aktion' => [['action' => 'purge'], 'Unbekannte Aktion'];
        yield 'remove ohne Nummer' => [['action' => 'remove', 'first' => 'x'], 'Nummer der Freigabe'];
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('invalid')]
    public function testInvalidInputChangesNothing(array $input, string $message): void
    {
        $tester = $this->runCommand($input);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay(true));
        self::assertSame([], $this->db()->fetchAll('SELECT id FROM http_internal_targets'));
        self::assertSame([], $this->audit());
    }
}
