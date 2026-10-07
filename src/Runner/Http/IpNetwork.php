<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Ein IP-Netz in CIDR-Schreibweise, geprüft über inet_pton und Bitmasken (ohne Abhängigkeit).
 */
final readonly class IpNetwork
{
    /**
     * @param string $network gepackte Netzadresse (4 oder 16 Byte), Hostbits 0
     */
    private function __construct(
        private string $network,
        public int $prefix,
    ) {
    }

    /**
     * Streng: `adresse/präfix`, Adresse in Normalform-tauglicher Schreibweise (IPv4 ohne führende Nullen, IPv6
     * ohne Zonen-ID), Präfix ohne führende Nullen, Hostbits 0 (sonst Fehler statt still kürzen).
     *
     * @throws \InvalidArgumentException mit fester Meldung
     */
    public static function fromCidr(string $cidr): self
    {
        if (preg_match('~^([0-9A-Fa-f:.]+)/(0|[1-9][0-9]{0,2})$~D', $cidr, $m) !== 1) {
            throw new \InvalidArgumentException('Ungültiges Netz. Form: 192.168.1.0/24 oder fd12:3456::/48.');
        }
        $packed = self::pack($m[1]);
        if ($packed === null) {
            throw new \InvalidArgumentException('Ungültiges Netz. Form: 192.168.1.0/24 oder fd12:3456::/48.');
        }
        $prefix = (int) $m[2];
        if ($prefix > strlen($packed) * 8) {
            throw new \InvalidArgumentException('Ungültige Präfixlänge (IPv4 höchstens 32, IPv6 höchstens 128).');
        }
        if (self::mask($packed, $prefix) !== $packed) {
            throw new \InvalidArgumentException('Im Netz sind Hostbits gesetzt. Bitte die Netzadresse angeben (z. B. 192.168.1.0/24 statt 192.168.1.5/24).');
        }

        return new self($packed, $prefix);
    }

    /**
     * Gepackte Adresse für eine gültige IPv4 (ohne führende Nullen) oder IPv6 (ohne Zonen-ID), sonst null.
     */
    public static function pack(string $ip): ?string
    {
        if (str_contains($ip, ':')) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
        } elseif (!UrlPolicy::isIpv4($ip)) {
            return null;
        }
        $packed = inet_pton($ip);

        return $packed === false ? null : $packed;
    }

    public function isIpv6(): bool
    {
        return strlen($this->network) === 16;
    }

    public function maxPrefix(): int
    {
        return strlen($this->network) * 8;
    }

    public function isSingleAddress(): bool
    {
        return $this->prefix === $this->maxPrefix();
    }

    /** Netzadresse als Text, z. B. für Klassifizierung. */
    public function address(): string
    {
        $text = inet_ntop($this->network);

        return $text === false ? '' : $text;
    }

    /** Normalisierte Schreibweise, wie sie in `http_internal_targets.value` steht. */
    public function toString(): string
    {
        return $this->address() . '/' . $this->prefix;
    }

    /**
     * @param string $packedIp gepackte Adresse (4 oder 16 Byte); andere Familie liegt nie im Netz
     */
    public function containsPacked(string $packedIp): bool
    {
        return strlen($packedIp) === strlen($this->network) && self::mask($packedIp, $this->prefix) === $this->network;
    }

    /** Liegt das andere Netz vollständig in diesem? */
    public function containsNetwork(self $other): bool
    {
        return $other->prefix >= $this->prefix && $this->containsPacked($other->network);
    }

    private static function mask(string $packed, int $prefix): string
    {
        $out = '';
        $length = strlen($packed);
        for ($i = 0; $i < $length; $i++) {
            $bits = max(0, min(8, $prefix - $i * 8));
            $byteMask = $bits === 0 ? 0 : (0xFF << (8 - $bits)) & 0xFF;
            $out .= chr(ord($packed[$i]) & $byteMask);
        }

        return $out;
    }
}
