<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Runner\Http;

use Meridian\Runner\Http\AddressPolicy;
use Meridian\Runner\Http\BlockReason;
use Meridian\Runner\Http\InternalTarget;
use Meridian\Runner\Http\InternalTargetSource;
use Meridian\Runner\Http\InvalidInternalTarget;
use Meridian\Runner\Http\IpNetwork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Klassen gesperrter Adressen (§5.2), Regeln für Freigaben (E5) und die nie freigebbaren Netze.
 */
final class AddressPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: BlockReason|null}>
     */
    public static function addresses(): iterable
    {
        yield '127.0.0.1' => ['127.0.0.1', BlockReason::Loopback];
        yield '127.255.255.254' => ['127.255.255.254', BlockReason::Loopback];
        yield '::1' => ['::1', BlockReason::Loopback];
        yield '0.0.0.0' => ['0.0.0.0', BlockReason::Reserved];
        yield '0.1.2.3' => ['0.1.2.3', BlockReason::Reserved];
        yield '::' => ['::', BlockReason::Reserved];
        yield '::7f00:1 (IPv4-kompatibel)' => ['::7f00:1', BlockReason::Reserved];
        yield '10.0.0.1' => ['10.0.0.1', BlockReason::Private];
        yield '172.16.0.1' => ['172.16.0.1', BlockReason::Private];
        yield '172.31.255.255' => ['172.31.255.255', BlockReason::Private];
        yield '172.32.0.1 öffentlich' => ['172.32.0.1', null];
        yield '192.168.1.1' => ['192.168.1.1', BlockReason::Private];
        yield '100.64.0.1' => ['100.64.0.1', BlockReason::Private];
        yield '100.128.0.1 öffentlich' => ['100.128.0.1', null];
        yield '169.254.169.254' => ['169.254.169.254', BlockReason::LinkLocal];
        yield 'fe80::1' => ['fe80::1', BlockReason::LinkLocal];
        yield 'fd00::1' => ['fd00::1', BlockReason::Private];
        yield 'fc00::1' => ['fc00::1', BlockReason::Private];
        yield 'fec0::1' => ['fec0::1', BlockReason::Reserved];
        yield '::ffff:127.0.0.1' => ['::ffff:127.0.0.1', BlockReason::Reserved];
        yield '::ffff:7f00:1' => ['::ffff:7f00:1', BlockReason::Reserved];
        yield '::ffff:192.168.1.5' => ['::ffff:192.168.1.5', BlockReason::Reserved];
        yield '::ffff:169.254.169.254' => ['::ffff:169.254.169.254', BlockReason::Reserved];
        yield '::ffff:8.8.8.8 öffentlich' => ['::ffff:8.8.8.8', null];
        yield '64:ff9b::7f00:1' => ['64:ff9b::7f00:1', BlockReason::Reserved];
        yield '64:ff9b::808:808 öffentlich' => ['64:ff9b::808:808', null];
        yield '64:ff9b:1::1 lokales NAT64' => ['64:ff9b:1::1', BlockReason::Reserved];
        yield '2002:7f00:1::' => ['2002:7f00:1::', BlockReason::Reserved];
        yield '2002:a9fe:a9fe::' => ['2002:a9fe:a9fe::', BlockReason::Reserved];
        yield '2002:808:808:: öffentlich' => ['2002:808:808::', null];
        yield '224.0.0.1' => ['224.0.0.1', BlockReason::Multicast];
        yield 'ff02::1' => ['ff02::1', BlockReason::Multicast];
        yield '255.255.255.255' => ['255.255.255.255', BlockReason::Reserved];
        yield '240.0.0.1' => ['240.0.0.1', BlockReason::Reserved];
        yield '198.18.0.1' => ['198.18.0.1', BlockReason::Reserved];
        yield '198.19.255.255' => ['198.19.255.255', BlockReason::Reserved];
        yield '192.0.0.8' => ['192.0.0.8', BlockReason::Reserved];
        yield '192.0.2.1' => ['192.0.2.1', BlockReason::Reserved];
        yield '192.88.99.1' => ['192.88.99.1', BlockReason::Reserved];
        yield '198.51.100.1' => ['198.51.100.1', BlockReason::Reserved];
        yield '203.0.113.1' => ['203.0.113.1', BlockReason::Reserved];
        yield '2001:db8::1' => ['2001:db8::1', BlockReason::Reserved];
        yield '2001::1 Teredo' => ['2001::1', BlockReason::Reserved];
        yield '100::1' => ['100::1', BlockReason::Reserved];
        yield '4000::1 außerhalb 2000::/3' => ['4000::1', BlockReason::Reserved];
        yield '8.8.8.8' => ['8.8.8.8', null];
        yield '1.1.1.1' => ['1.1.1.1', null];
        yield '2606:4700:4700::1111' => ['2606:4700:4700::1111', null];
        yield 'ungültig' => ['127.1', BlockReason::Reserved];
        yield 'führende Null' => ['010.0.0.1', BlockReason::Reserved];
        yield 'Zonen-ID' => ['fe80::1%eth0', BlockReason::Reserved];
    }

    #[DataProvider('addresses')]
    public function testClassification(string $ip, ?BlockReason $expected): void
    {
        self::assertSame($expected, AddressPolicy::classify($ip));
        self::assertSame($expected, (new AddressPolicy(self::targets([])))->check($ip, 'ziel.example', 443, 1), 'ohne Freigaben');
    }

    public function testNeverReleasableEvenWithEveryPossibleRelease(): void
    {
        $everything = self::targets([
            InternalTarget::create('cidr', '10.0.0.0/8', 0, null),
            InternalTarget::create('cidr', '172.16.0.0/12', 0, null),
            InternalTarget::create('cidr', '192.168.0.0/16', 0, null),
            InternalTarget::create('cidr', '100.64.0.0/10', 0, null),
            InternalTarget::create('cidr', 'fd00::/16', 0, null),
            InternalTarget::create('cidr', '127.0.0.1/32', 80, null),
            InternalTarget::create('cidr', '::1/128', 80, null),
            InternalTarget::create('host', 'ziel.example', 0, null),
        ]);
        $policy = new AddressPolicy($everything);
        foreach (['169.254.169.254', 'fe80::1', '0.0.0.0', '::', '224.0.0.1', '255.255.255.255', '198.18.0.1', '::ffff:192.168.1.5', '::ffff:127.0.0.1', '64:ff9b::a00:1', '2002:c0a8:105::', '127.0.0.2', '::ffff:7f00:1'] as $ip) {
            self::assertNotNull($policy->check($ip, 'ziel.example', 80, null), $ip);
        }
        self::assertNull($policy->check('127.0.0.1', 'ziel.example', 80, null));
        self::assertNull($policy->check('::1', 'ziel.example', 80, null));
        self::assertNull($policy->check('192.168.1.5', 'irgendwas.example', 80, null));
        self::assertFalse(BlockReason::LinkLocal->isReleasable());
        self::assertFalse(BlockReason::Infrastructure->isReleasable());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: string}>
     */
    public static function invalidReleases(): iterable
    {
        yield 'Loopback-Netz' => ['cidr', '127.0.0.0/8', 8080, 'value'];
        yield 'Loopback einzeln ohne Port' => ['cidr', '127.0.0.1/32', 0, 'port'];
        yield 'IPv6-Loopback ohne Port' => ['cidr', '::1/128', 0, 'port'];
        yield 'Link-local' => ['cidr', '169.254.0.0/16', 0, 'value'];
        yield 'Metadaten einzeln' => ['cidr', '169.254.169.254/32', 80, 'value'];
        yield 'alles' => ['cidr', '0.0.0.0/0', 0, 'value'];
        yield '0.0.0.0/8' => ['cidr', '0.0.0.0/8', 0, 'value'];
        yield 'Hostbits' => ['cidr', '192.168.1.5/24', 0, 'value'];
        yield 'zu groß' => ['cidr', '10.0.0.0/7', 0, 'value'];
        yield 'teils privat' => ['cidr', '172.0.0.0/8', 0, 'value'];
        yield 'öffentlich' => ['cidr', '8.8.8.0/24', 0, 'value'];
        yield 'Multicast' => ['cidr', '224.0.0.0/4', 0, 'value'];
        yield 'fe80' => ['cidr', 'fe80::/10', 0, 'value'];
        yield 'IPv6 zu groß' => ['cidr', 'fc00::/7', 0, 'value'];
        yield 'IPv6 zu kurzes Präfix' => ['cidr', '::/8', 0, 'value'];
        yield 'gemappt' => ['cidr', '::ffff:192.168.1.0/120', 0, 'value'];
        yield 'ohne Präfix' => ['cidr', '192.168.1.0', 0, 'value'];
        yield 'Präfix zu lang' => ['cidr', '192.168.1.0/33', 0, 'value'];
        yield 'Präfix mit Null' => ['cidr', '192.168.1.0/024', 0, 'value'];
        yield 'führende Null' => ['cidr', '192.168.001.0/24', 0, 'value'];
        yield 'Zonen-ID' => ['cidr', 'fd00::%eth0/64', 0, 'value'];
        yield 'localhost' => ['host', 'localhost', 0, 'value'];
        yield 'sub.localhost' => ['host', 'a.localhost', 0, 'value'];
        yield 'Metadaten' => ['host', 'metadata.google.internal', 0, 'value'];
        yield 'metadata' => ['host', 'metadata', 0, 'value'];
        yield 'IP als Host' => ['host', '192.168.1.5', 0, 'value'];
        yield 'IPv6 als Host' => ['host', 'fd00::1', 0, 'value'];
        yield 'Unterstrich' => ['host', 'my_service', 0, 'value'];
        yield 'leer' => ['host', '', 0, 'value'];
        yield 'Port negativ' => ['host', 'nextcloud', -1, 'port'];
        yield 'Port zu groß' => ['host', 'nextcloud', 65536, 'port'];
        yield 'Art unbekannt' => ['ip', '192.168.1.5', 0, 'kind'];
    }

    #[DataProvider('invalidReleases')]
    public function testInvalidReleasesAreRejected(string $kind, string $value, int $port, string $field): void
    {
        try {
            InternalTarget::create($kind, $value, $port, null);
            self::fail('angenommen');
        } catch (InvalidInternalTarget $e) {
            self::assertSame($field, $e->field);
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testValidReleasesAreNormalised(): void
    {
        self::assertSame('192.168.1.0/24', InternalTarget::create('cidr', '192.168.1.0/24', 0, 3)->value);
        self::assertSame('fd12:3456::/48', InternalTarget::create('cidr', 'FD12:3456:0::/48', 443, null)->value);
        self::assertSame('127.0.0.1/32', InternalTarget::create('cidr', '127.0.0.1/32', 8099, null)->value);
        self::assertSame('10.1.2.3/32', InternalTarget::create('cidr', '10.1.2.3/32', 0, null)->value);
        self::assertSame('nextcloud', InternalTarget::create('host', 'NextCloud', 0, 1)->value);
        self::assertSame('intranet.firma.local', InternalTarget::create('host', 'intranet.firma.local', 443, null)->value);
        self::assertSame(1, InternalTarget::create('host', 'nextcloud', 0, 1)->categoryId);
    }

    public function testCategoryAndPortAreCheckedEvenIfTheSourceReturnsTooMuch(): void
    {
        // Eine Quelle, die (fehlerhaft) Freigaben fremder Kategorien liefert: die Policy filtert selbst.
        $policy = new AddressPolicy(self::targets([
            InternalTarget::create('cidr', '192.168.1.0/24', 0, 1),
            InternalTarget::create('cidr', '10.0.0.0/8', 8081, null),
        ]));

        self::assertNull($policy->check('192.168.1.5', 'x.example', 80, 1));
        self::assertSame(BlockReason::Private, $policy->check('192.168.1.5', 'x.example', 80, 2));
        self::assertSame(BlockReason::Private, $policy->check('192.168.1.5', 'x.example', 80, null), 'ohne Kategorie nur globale');
        self::assertNull($policy->check('10.0.0.1', 'x.example', 8081, null));
        self::assertSame(BlockReason::Private, $policy->check('10.0.0.1', 'x.example', 8082, null));
    }

    public function testRuntimeChecksHoldEvenForForgedReleases(): void
    {
        // An create() vorbei erzeugt (simuliert einen Fehler in der Anlageprüfung): die Laufzeitregeln halten.
        $forge = \Closure::bind(
            static fn (string $kind, string $value, int $port, ?IpNetwork $network): InternalTarget => new InternalTarget(
                $kind === 'cidr' ? InternalTarget::KIND_CIDR : InternalTarget::KIND_HOST,
                $value,
                $port,
                null,
                $network,
            ),
            null,
            InternalTarget::class,
        );
        self::assertInstanceOf(\Closure::class, $forge);
        $policy = new AddressPolicy(self::targets([
            $forge('cidr', '127.0.0.0/8', 0, IpNetwork::fromCidr('127.0.0.0/8')),
            $forge('cidr', '::1/128', 0, IpNetwork::fromCidr('::1/128')),
            $forge('host', '172.18.0.4', 0, null),
            $forge('cidr', '0.0.0.0/0', 0, IpNetwork::fromCidr('0.0.0.0/0')),
        ]));

        self::assertSame(BlockReason::Loopback, $policy->check('127.0.0.1', 'x.example', 8081, null), 'Loopback nur einzeln mit Port');
        self::assertSame(BlockReason::Loopback, $policy->check('::1', 'x.example', 8081, null));
        self::assertSame(BlockReason::LinkLocal, $policy->check('169.254.169.254', 'x.example', 80, null));
        self::assertSame(BlockReason::Private, $policy->check('172.18.0.4', 'x.example', 80, null), '0.0.0.0/0 gibt nichts frei');
        self::assertNull((new AddressPolicy(self::targets([$forge('cidr', '172.16.0.0/12', 0, IpNetwork::fromCidr('172.16.0.0/12'))])))->check('172.18.0.4', 'x.example', 80, null));
        $loopNet = new AddressPolicy(self::targets([$forge('cidr', '127.0.0.0/8', 8080, IpNetwork::fromCidr('127.0.0.0/8'))]));
        self::assertSame(BlockReason::Loopback, $loopNet->check('127.0.0.1', 'x.example', 8081, null), 'Loopback-Netz gibt nichts frei, auch mit Port');
        $hostOnly = new AddressPolicy(self::targets([$forge('host', '172.18.0.4', 0, null)]));
        self::assertSame(BlockReason::Private, $hostOnly->check('172.18.0.4', '172.18.0.4', 80, null), 'Host-Freigabe nie für ein IP-Literal');
        self::assertSame(BlockReason::Private, $hostOnly->check('172.18.0.4', 'fd00::1', 80, null));
    }

    public function testIpNetworkMaths(): void
    {
        $net = IpNetwork::fromCidr('192.168.0.0/16');
        self::assertTrue($net->containsNetwork(IpNetwork::fromCidr('192.168.1.0/24')));
        self::assertFalse(IpNetwork::fromCidr('192.168.1.0/24')->containsNetwork($net));
        self::assertFalse($net->containsNetwork(IpNetwork::fromCidr('fd00::/8')), 'andere Familie');
        self::assertTrue(IpNetwork::fromCidr('172.16.0.0/12')->containsPacked((string) inet_pton('172.31.0.1')));
        self::assertFalse(IpNetwork::fromCidr('172.16.0.0/12')->containsPacked((string) inet_pton('172.32.0.1')));
        self::assertTrue(IpNetwork::fromCidr('0.0.0.0/0')->containsPacked((string) inet_pton('8.8.8.8')));
        self::assertTrue(IpNetwork::fromCidr('fe80::/10')->containsPacked((string) inet_pton('febf::1')));
        self::assertFalse(IpNetwork::fromCidr('fe80::/10')->containsPacked((string) inet_pton('fec0::1')));
        self::assertTrue(IpNetwork::fromCidr('::1/128')->isSingleAddress());
    }

    /**
     * @param list<InternalTarget> $list
     */
    private static function targets(array $list): InternalTargetSource
    {
        return new class ($list) implements InternalTargetSource {
            /**
             * @param list<InternalTarget> $list
             */
            public function __construct(private readonly array $list)
            {
            }

            public function targetsFor(?int $categoryId): array
            {
                return $this->list;
            }
        };
    }
}
