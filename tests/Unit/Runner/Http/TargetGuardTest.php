<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Runner\Http;

use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Runner\Http\AddressPolicy;
use Meridian\Runner\Http\BlockReason;
use Meridian\Runner\Http\DbInternalTargetSource;
use Meridian\Runner\Http\SystemHostResolver;
use Meridian\Runner\Http\TargetBlocked;
use Meridian\Runner\Http\TargetGuard;
use Meridian\Runner\Http\TargetUnresolvable;
use Meridian\Runner\Http\UrlPolicy;
use Meridian\Tests\Support\FakeHostResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SSRF-Adressfälle (§7.1) und die Freigabe-Auflösung (§7.2) über die echte Tabelle `http_internal_targets`.
 * DNS über einen Fake-Resolver; Namen unter `.invalid` (RFC 6761) können nie wirklich aufgelöst werden.
 */
final class TargetGuardTest extends TestCase
{
    private const CAT_A = 1;
    private const CAT_B = 2;

    private ?Connection $connection = null;

    private ?FakeHostResolver $resolver = null;

    private function db(): Connection
    {
        if ($this->connection === null) {
            $this->connection = Connection::inMemory();
            (new Migrator($this->connection, dirname(__DIR__, 4) . '/migrations'))->migrate();
            $this->connection->execute("INSERT INTO categories (id, name) VALUES (1, 'A'), (2, 'B')");
        }

        return $this->connection;
    }

    private function dns(): FakeHostResolver
    {
        return $this->resolver ??= new FakeHostResolver();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function blockedLiterals(): iterable
    {
        foreach (['127.0.0.1', '[::1]', '0.0.0.0', '10.0.0.1', '172.16.0.1', '192.168.1.1', '100.64.0.1', '169.254.169.254', '[fe80::1]', '[fd00::1]', '[::ffff:127.0.0.1]', '[::ffff:7f00:1]', '[64:ff9b::7f00:1]', '[2002:7f00:1::]', '224.0.0.1', '255.255.255.255', '198.18.0.1'] as $host) {
            yield $host => [$host];
        }
    }

    #[DataProvider('blockedLiterals')]
    public function testBlockedAddressesWithoutRelease(string $host): void
    {
        try {
            $this->guard()->pin((new UrlPolicy())->parse('http://' . $host . ':8080/'), self::CAT_A);
            self::fail('nicht gesperrt');
        } catch (TargetBlocked $e) {
            $plain = trim($host, '[]');
            self::assertStringNotContainsString($plain, $e->getMessage(), 'Notiz ohne Adresse');
            self::assertStringStartsWith('Ziel gesperrt:', $e->getMessage());
        }
    }

    public function testBlockedMessagePromisesReleaseOnlyWhereItHelps(): void
    {
        self::assertStringContainsString('Ein Admin kann interne Ziele freigeben', $this->blocked('http://192.168.1.1/', self::CAT_A)->getMessage());
        self::assertStringContainsString('Ein Admin kann interne Ziele freigeben', $this->blocked('http://127.0.0.1/', self::CAT_A)->getMessage());
        $metadata = $this->blocked('http://169.254.169.254/', self::CAT_A);
        self::assertSame(BlockReason::LinkLocal, $metadata->reason);
        self::assertStringNotContainsString('freigeben (global', $metadata->getMessage());
        self::assertStringContainsString('nie freigegeben', $metadata->getMessage());
    }

    public function testPublicTargetsArePinned(): void
    {
        $this->dns()->set('ziel.invalid', ['8.8.8.8', '2606:4700:4700::1111', '8.8.8.8']);
        $pinned = $this->guard()->pin((new UrlPolicy())->parse('https://ziel.invalid/x?key=TEST-SECRET-do-not-use-123'), null);

        self::assertSame(['8.8.8.8', '2606:4700:4700::1111'], $pinned->ips);
        self::assertSame('ziel.invalid', $pinned->url->host);
        self::assertSame(['1.1.1.1'], $this->guard()->pin((new UrlPolicy())->parse('http://1.1.1.1/'), null)->ips, 'IP-Literal ohne DNS');
        self::assertSame(['2606:4700:4700::1111'], $this->guard()->pin((new UrlPolicy())->parse('http://[2606:4700:4700:0::1111]/'), null)->ips);
    }

    public function testDnsCases(): void
    {
        $this->dns()->set('privat.invalid', ['10.1.2.3']);
        self::assertSame(BlockReason::Private, $this->blocked('http://privat.invalid/', self::CAT_A)->reason);

        $this->dns()->set('gemischt.invalid', ['8.8.8.8', '192.168.1.5']);
        self::assertSame(BlockReason::Private, $this->blocked('http://gemischt.invalid/', self::CAT_A)->reason, 'öffentlich und privat: ganz abgelehnt');

        $this->dns()->set('gemischt2.invalid', ['192.168.1.5', '169.254.169.254']);
        self::assertSame(BlockReason::LinkLocal, $this->blocked('http://gemischt2.invalid/', self::CAT_A)->reason, 'nie freigebbarer Grund hat Vorrang');

        $this->dns()->set('leer.invalid', []);
        $this->expectUnresolvable('http://leer.invalid/');
        $this->expectUnresolvable('http://unbekannt.invalid/');
        $this->dns()->set('kaputt.invalid', ['8.8.8.8', 'kein-ip']);
        $this->expectUnresolvable('http://kaputt.invalid/');
        $this->dns()->set('kurz.invalid', ['127.1']);
        $this->expectUnresolvable('http://kurz.invalid/');
    }

    public function testRebindingOnlyThePinnedAnswerCounts(): void
    {
        $this->dns()->set('rebind.invalid', ['8.8.8.8'], ['127.0.0.1']);
        $guard = $this->guard();
        $url = (new UrlPolicy())->parse('http://rebind.invalid/');

        self::assertSame(['8.8.8.8'], $guard->pin($url, null)->ips);
        self::assertSame(1, $this->dns()->lookups['rebind.invalid'] ?? 0, 'genau eine Auflösung je Hop');
        // Der nächste Hop löst neu auf und prüft neu.
        try {
            $guard->pin($url, null);
            self::fail('zweite Auflösung nicht geprüft');
        } catch (TargetBlocked $e) {
            self::assertSame(BlockReason::Loopback, $e->reason);
        }
    }

    public function testReleaseResolutionFromTheDecisionTable(): void
    {
        // cidr 192.168.1.0/24 global
        $this->release('cidr', '192.168.1.0/24', 0, null);
        self::assertTrue($this->allowed('http://192.168.1.5:80/', self::CAT_A));
        self::assertFalse($this->allowed('http://192.168.2.5:80/', self::CAT_A));
        self::assertTrue($this->allowed('http://192.168.1.5/', null), 'global gilt auch ohne Kategorie');
        self::assertFalse($this->allowed('http://[::ffff:192.168.1.5]/', self::CAT_A), 'gemappte Form nie');
        $this->db()->execute('DELETE FROM http_internal_targets');

        // cidr 192.168.1.0/24 nur Kategorie A
        $this->release('cidr', '192.168.1.0/24', 0, self::CAT_A);
        self::assertTrue($this->allowed('http://192.168.1.5/', self::CAT_A));
        self::assertFalse($this->allowed('http://192.168.1.5/', self::CAT_B));
        self::assertFalse($this->allowed('http://192.168.1.5/', null));
        $this->db()->execute('DELETE FROM http_internal_targets');

        // cidr 10.0.0.0/8 Port 8080 global
        $this->release('cidr', '10.0.0.0/8', 8080, null);
        self::assertTrue($this->allowed('http://10.1.2.3:8080/', self::CAT_A));
        self::assertFalse($this->allowed('http://10.1.2.3:8081/', self::CAT_A));
        self::assertFalse($this->allowed('http://10.1.2.3/', self::CAT_A));
        $this->db()->execute('DELETE FROM http_internal_targets');

        // host nextcloud, Kategorie A
        $this->release('host', 'nextcloud', 0, self::CAT_A);
        $this->dns()->set('nextcloud', ['172.18.0.4']);
        self::assertSame(['172.18.0.4'], $this->guard()->pin((new UrlPolicy())->parse('http://nextcloud/'), self::CAT_A)->ips, 'erlaubt und gepinnt');
        $this->dns()->set('nextcloud', ['127.0.0.1']);
        self::assertSame(BlockReason::Loopback, $this->blocked('http://nextcloud/', self::CAT_A)->reason, 'Host gibt nur privat frei');
        $this->dns()->set('nextcloud', ['169.254.169.254']);
        self::assertSame(BlockReason::LinkLocal, $this->blocked('http://nextcloud/', self::CAT_A)->reason);
        $this->dns()->set('nextcloud', ['172.18.0.4', '127.0.0.1']);
        self::assertSame(BlockReason::Loopback, $this->blocked('http://nextcloud/', self::CAT_A)->reason, 'jede Adresse muss erlaubt sein');
        $this->dns()->set('cloud.example', ['172.18.0.4']);
        self::assertFalse($this->allowed('http://cloud.example/', self::CAT_A), 'anderer Name');
        $this->dns()->set('nextcloud', ['172.18.0.4']);
        self::assertFalse($this->allowed('http://nextcloud/', self::CAT_B));
        self::assertFalse($this->allowed('http://172.18.0.4/', self::CAT_A), 'Host-Freigabe gilt nicht für das IP-Literal');
        $this->db()->execute('DELETE FROM http_internal_targets');

        // host nextcloud global, Weiterleitung auf db:5432
        $this->release('host', 'nextcloud', 0, null);
        $this->dns()->set('nextcloud', ['172.18.0.4']);
        $this->dns()->set('db', ['172.18.0.5']);
        self::assertTrue($this->allowed('http://nextcloud/', self::CAT_A));
        self::assertFalse($this->allowed('http://db:5432/', self::CAT_A), 'Hop neu geprüft');
        $this->db()->execute('DELETE FROM http_internal_targets');

        // cidr 127.0.0.1/32 Port 8099 global
        $this->release('cidr', '127.0.0.1/32', 8099, null);
        self::assertTrue($this->allowed('http://127.0.0.1:8099/', self::CAT_A));
        self::assertFalse($this->allowed('http://127.0.0.1:8080/', self::CAT_A));
        self::assertFalse($this->allowed('http://127.0.0.2:8099/', self::CAT_A));
        self::assertFalse($this->allowed('http://[::ffff:127.0.0.1]:8099/', self::CAT_A));
    }

    public function testDeletedCategoryRemovesItsReleasesAndMovingAJobLosesThem(): void
    {
        $this->release('cidr', '192.168.1.0/24', 0, self::CAT_A);
        self::assertTrue($this->allowed('http://192.168.1.5/', self::CAT_A));
        // Job von A nach B verschoben: die Kategorie kommt beim nächsten Lauf aus der DB.
        self::assertFalse($this->allowed('http://192.168.1.5/', self::CAT_B));

        $this->db()->execute('DELETE FROM categories WHERE id = :id', ['id' => self::CAT_A]);
        self::assertFalse($this->allowed('http://192.168.1.5/', self::CAT_A), 'Freigabe mit der Kategorie gelöscht');
        self::assertFalse($this->allowed('http://192.168.1.5/', null), 'nie global geworden');
    }

    public function testTamperedRowsNeverWidenAnything(): void
    {
        foreach ([['cidr', '0.0.0.0/0', 0], ['cidr', '127.0.0.0/8', 0], ['cidr', '169.254.0.0/16', 0], ['cidr', '192.168.1.5/24', 0], ['host', 'localhost', 0], ['cidr', '127.0.0.1/32', 0], ['cidr', 'FD00::/8', 0], ['host', 'NextCloud', 0]] as [$kind, $value, $port]) {
            $this->db()->execute(
                'INSERT INTO http_internal_targets (kind, value, port, category_id, created_at) VALUES (:k, :v, :p, NULL, :t)',
                ['k' => $kind, 'v' => $value, 'p' => $port, 't' => '2026-10-07T00:00:00+00:00'],
            );
        }
        $this->dns()->set('nextcloud', ['172.18.0.4']);
        $log = ini_get('error_log');
        $file = tempnam(sys_get_temp_dir(), 'meridian-log');
        self::assertIsString($file);
        ini_set('error_log', $file);
        try {
            foreach (['http://10.0.0.1/', 'http://127.0.0.1/', 'http://169.254.169.254/', 'http://192.168.1.5/', 'http://[fd00::1]/', 'http://nextcloud/'] as $url) {
                self::assertFalse($this->allowed($url, self::CAT_A), $url);
            }
            $written = (string) file_get_contents($file);
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
            unlink($file);
        }
        self::assertStringContainsString('Freigabe interner Ziele Nr.', $written);
        self::assertStringNotContainsString('0.0.0.0/0', $written, 'Log ohne Werte');
    }

    public function testDatabaseSourceLoadsOnlyGlobalAndTheJobsCategory(): void
    {
        $this->release('cidr', '192.168.1.0/24', 0, self::CAT_A);
        $this->release('cidr', '192.168.2.0/24', 0, self::CAT_B);
        $this->release('host', 'nextcloud', 443, null);
        $source = new DbInternalTargetSource($this->db());
        $values = static fn (?int $category): array => array_map(static fn ($t): string => $t->value . '@' . ($t->categoryId ?? 'global'), $source->targetsFor($category));

        self::assertSame(['192.168.1.0/24@1', 'nextcloud@global'], $values(self::CAT_A));
        self::assertSame(['192.168.2.0/24@2', 'nextcloud@global'], $values(self::CAT_B));
        self::assertSame(['nextcloud@global'], $values(null));
        self::assertSame(['nextcloud@global'], $values(99));
    }

    public function testSystemResolverAnswersIpLiteralsWithoutDns(): void
    {
        $resolver = new SystemHostResolver();
        self::assertSame(['192.0.2.1'], $resolver->resolve('192.0.2.1'));
        self::assertSame(['2001:db8::1'], $resolver->resolve('2001:db8::1'));
        self::assertSame([], $resolver->resolve('meridian-test.invalid'), '.invalid ist nie auflösbar');
    }

    private function release(string $kind, string $value, int $port, ?int $category): void
    {
        $this->db()->execute(
            'INSERT INTO http_internal_targets (kind, value, port, category_id, created_at) VALUES (:k, :v, :p, :c, :t)',
            ['k' => $kind, 'v' => $value, 'p' => $port, 'c' => $category, 't' => '2026-10-07T00:00:00+00:00'],
        );
    }

    private function allowed(string $url, ?int $category): bool
    {
        try {
            $this->guard()->pin((new UrlPolicy())->parse($url), $category);

            return true;
        } catch (TargetBlocked) {
            return false;
        }
    }

    private function blocked(string $url, ?int $category): TargetBlocked
    {
        try {
            $this->guard()->pin((new UrlPolicy())->parse($url), $category);
        } catch (TargetBlocked $e) {
            return $e;
        }
        self::fail('nicht gesperrt: ' . $url);
    }

    private function expectUnresolvable(string $url): void
    {
        try {
            $this->guard()->pin((new UrlPolicy())->parse($url), self::CAT_A);
            self::fail('aufgelöst: ' . $url);
        } catch (TargetUnresolvable $e) {
            self::assertSame('Ziel nicht auflösbar: Hostname prüfen.', $e->getMessage());
        }
    }

    private function guard(): TargetGuard
    {
        return new TargetGuard($this->dns(), new AddressPolicy(new DbInternalTargetSource($this->db())));
    }
}
