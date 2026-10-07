<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Darf ein HTTP-Job diese Adresse ansprechen? (docs/decisions/0003, E5 und §5.2)
 *
 * Öffentliche Adressen: ja. Gesperrte Klassen „privat“ und „Loopback“: nur mit passender Freigabe (global oder
 * für die gespeicherte Kategorie des Jobs, Port passend, je Hop mit dessen Host und Port). Alle anderen Klassen
 * (Link-local inkl. Metadaten, reserviert, Multicast, Infrastruktur): nie.
 *
 * IPv6 nach Allowlist: öffentlich ist nur 2000::/3 ohne die reservierten Teilnetze; eingebettete IPv4
 * (::ffff:0:0/96, 64:ff9b::/96, 2002::/16) zählt wie die IPv4 und ist, wenn diese nicht öffentlich ist, nie
 * freigebbar.
 */
final class AddressPolicy
{
    private const V4 = [
        ['0.0.0.0/8', BlockReason::Reserved],
        ['10.0.0.0/8', BlockReason::Private],
        ['100.64.0.0/10', BlockReason::Private],
        ['127.0.0.0/8', BlockReason::Loopback],
        ['169.254.0.0/16', BlockReason::LinkLocal],
        ['172.16.0.0/12', BlockReason::Private],
        ['192.0.0.0/24', BlockReason::Reserved],
        ['192.0.2.0/24', BlockReason::Reserved],
        ['192.88.99.0/24', BlockReason::Reserved],
        ['192.168.0.0/16', BlockReason::Private],
        ['198.18.0.0/15', BlockReason::Reserved],
        ['198.51.100.0/24', BlockReason::Reserved],
        ['203.0.113.0/24', BlockReason::Reserved],
        ['224.0.0.0/4', BlockReason::Multicast],
        ['240.0.0.0/4', BlockReason::Reserved],
    ];

    private const V6 = [
        ['::1/128', BlockReason::Loopback],
        ['::/96', BlockReason::Reserved],
        ['100::/64', BlockReason::Reserved],
        ['2001::/23', BlockReason::Reserved],
        ['2001:db8::/32', BlockReason::Reserved],
        ['fc00::/7', BlockReason::Private],
        ['fe80::/10', BlockReason::LinkLocal],
        ['fec0::/10', BlockReason::Reserved],
        ['ff00::/8', BlockReason::Multicast],
    ];

    /** Präfix → Byte-Offset der eingebetteten IPv4. */
    private const EMBEDDED_V4 = [
        ['::ffff:0:0/96', 12],
        ['64:ff9b::/96', 12],
        ['2002::/16', 2],
    ];

    private const GLOBAL_UNICAST = '2000::/3';

    /** @var list<array{0: IpNetwork, 1: BlockReason}>|null */
    private static ?array $ranges = null;

    public function __construct(private readonly InternalTargetSource $targets)
    {
    }

    /**
     * @param string   $ip         die Adresse, zu der verbunden würde
     * @param string   $host       Host des aktuellen Hops aus der URL (Kleinbuchstaben)
     * @param int      $port       Port des aktuellen Hops
     * @param int|null $categoryId gespeicherte Kategorie des Jobs aus der Datenbank
     *
     * @return BlockReason|null null = erlaubt
     */
    public function check(string $ip, string $host, int $port, ?int $categoryId): ?BlockReason
    {
        $packed = IpNetwork::pack($ip);
        if ($packed === null) {
            return BlockReason::Reserved;
        }
        $reason = self::classifyPacked($packed);
        if ($reason === null || !$reason->isReleasable()) {
            return $reason;
        }

        foreach ($this->targets->targetsFor($categoryId) as $target) {
            if ($target->appliesTo($categoryId, $port) && $target->releases($packed, $reason, $host, $port)) {
                return null;
            }
        }

        return $reason;
    }

    /**
     * Klasse einer Adresse ohne Freigaben; null = öffentlich. Ungültige Schreibweise gilt als reserviert.
     */
    public static function classify(string $ip): ?BlockReason
    {
        $packed = IpNetwork::pack($ip);

        return $packed === null ? BlockReason::Reserved : self::classifyPacked($packed);
    }

    /** Liegt das Netz vollständig in einem Netz der Klasse „privat“? */
    public static function isWithinPrivate(IpNetwork $network): bool
    {
        foreach (self::ranges() as [$range, $reason]) {
            if ($reason === BlockReason::Private && $range->containsNetwork($network)) {
                return true;
            }
        }

        return false;
    }

    private static function classifyPacked(string $packed): ?BlockReason
    {
        if (strlen($packed) === 16) {
            if ($packed === str_repeat("\0", 15) . "\1") {
                return BlockReason::Loopback;
            }
            foreach (self::EMBEDDED_V4 as [$prefix, $offset]) {
                if (IpNetwork::fromCidr($prefix)->containsPacked($packed)) {
                    return self::classifyPacked(substr($packed, $offset, 4)) === null ? null : BlockReason::Reserved;
                }
            }
        }

        foreach (self::ranges() as [$range, $reason]) {
            if ($range->containsPacked($packed)) {
                return $reason;
            }
        }

        if (strlen($packed) === 16 && !IpNetwork::fromCidr(self::GLOBAL_UNICAST)->containsPacked($packed)) {
            return BlockReason::Reserved;
        }

        return null;
    }

    /**
     * @return list<array{0: IpNetwork, 1: BlockReason}>
     */
    private static function ranges(): array
    {
        if (self::$ranges === null) {
            $ranges = [];
            foreach ([...self::V4, ...self::V6] as [$cidr, $reason]) {
                $ranges[] = [IpNetwork::fromCidr($cidr), $reason];
            }
            self::$ranges = $ranges;
        }

        return self::$ranges;
    }
}
