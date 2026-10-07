<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Eine Freigabe eines internen Ziels (Tabelle `http_internal_targets`, docs/decisions/0003, E5).
 *
 * - `cidr`: ein Netz, das vollständig in „privat“ liegt, oder eine einzelne Loopback-Adresse (`/32`, `/128`)
 *   mit Port ≠ 0.
 * - `host`: ein exakter Hostname; gibt nur Adressen der Klasse „privat“ frei, nie Loopback.
 * - `port` 0 = alle Ports; `categoryId` null = global, sonst nur für Jobs dieser Kategorie.
 *
 * Entsteht nur über create(): dieselbe Prüfung beim Anlegen und beim Laden aus der Datenbank. Nie freigebbare
 * Netze (Link-local/Metadaten, 0.0.0.0/8, Multicast, reserviert) kommen so nie in eine Freigabe.
 */
final readonly class InternalTarget
{
    public const KIND_CIDR = 'cidr';
    public const KIND_HOST = 'host';

    private const MIN_PREFIX_V4 = 8;
    private const MIN_PREFIX_V6 = 16;

    /**
     * @param self::KIND_* $kind
     */
    private function __construct(
        public string $kind,
        public string $value,
        public int $port,
        public ?int $categoryId,
        private ?IpNetwork $network,
    ) {
    }

    /**
     * @throws InvalidInternalTarget mit Feld und Meldung, nie mit dem Wert
     */
    public static function create(string $kind, string $value, int $port, ?int $categoryId): self
    {
        if ($port < 0 || $port > 65535) {
            throw new InvalidInternalTarget('port', 'Ungültiger Port. Erlaubt sind 1 bis 65535 oder 0 für alle Ports.');
        }

        if ($kind === self::KIND_HOST) {
            $host = strtolower($value);
            if (!UrlPolicy::isValidHostname($host)) {
                throw new InvalidInternalTarget('value', 'Ungültiger Hostname. Erlaubt sind Buchstaben, Ziffern, Bindestriche und Punkte; IP-Adressen bitte als Netz (cidr) freigeben.');
            }
            if (UrlPolicy::isBlockedHostname($host)) {
                throw new InvalidInternalTarget('value', 'Dieser Hostname ist nie freigebbar (localhost, Metadaten-Dienst).');
            }

            return new self(self::KIND_HOST, $host, $port, $categoryId, null);
        }

        if ($kind !== self::KIND_CIDR) {
            throw new InvalidInternalTarget('kind', 'Unbekannte Art. Erlaubt sind „cidr“ (Netz) und „host“ (Hostname).');
        }

        try {
            $network = IpNetwork::fromCidr($value);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidInternalTarget('value', $e->getMessage());
        }
        $minimum = $network->isIpv6() ? self::MIN_PREFIX_V6 : self::MIN_PREFIX_V4;
        if ($network->prefix < $minimum) {
            throw new InvalidInternalTarget('value', 'Das Netz ist zu groß (IPv4 mindestens /8, IPv6 mindestens /16).');
        }

        if (!AddressPolicy::isWithinPrivate($network)) {
            if ($network->isSingleAddress() && AddressPolicy::classify($network->address()) === BlockReason::Loopback) {
                if ($port === 0) {
                    throw new InvalidInternalTarget('port', 'Loopback-Adressen sind nur einzeln mit einem bestimmten Port freigebbar.');
                }
            } else {
                throw new InvalidInternalTarget('value', 'Das Netz liegt nicht vollständig in einem privaten Netz (10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7). Loopback nur als einzelne Adresse mit Port; Link-local, Metadaten, 0.0.0.0/8, Multicast und reservierte Netze sind nie freigebbar.');
            }
        }

        return new self(self::KIND_CIDR, $network->toString(), $port, $categoryId, $network);
    }

    /** Gilt die Freigabe für einen Job dieser (gespeicherten) Kategorie und diesen Port? */
    public function appliesTo(?int $jobCategoryId, int $port): bool
    {
        return ($this->categoryId === null || $this->categoryId === $jobCategoryId)
            && ($this->port === 0 || $this->port === $port);
    }

    /**
     * Gibt die Freigabe diese gesperrte Adresse frei? Nur für die freigebbaren Klassen; alles andere nie.
     *
     * @param string $packedIp gepackte Adresse
     * @param string $host     Host des aktuellen Hops (Kleinbuchstaben)
     */
    public function releases(string $packedIp, BlockReason $reason, string $host, int $port): bool
    {
        if ($this->network !== null) {
            if (!$this->network->containsPacked($packedIp)) {
                return false;
            }

            // Auch zur Laufzeit: ein Netz gibt nur frei, wenn es selbst vollständig privat ist (zweite Absicherung
            // neben create()).
            return ($reason === BlockReason::Private && AddressPolicy::isWithinPrivate($this->network))
                || ($reason === BlockReason::Loopback && $this->network->isSingleAddress() && $this->port !== 0 && $this->port === $port);
        }

        // Host-Freigabe: nur „privat“, nur für einen Namen (nie für ein IP-Literal), exakt gleich.
        return $reason === BlockReason::Private
            && !UrlPolicy::isIpv4($host) && !str_contains($host, ':')
            && $this->value === $host;
    }
}
