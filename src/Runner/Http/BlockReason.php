<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Warum eine Adresse kein Ziel eines HTTP-Jobs sein darf (docs/decisions/0003, §5.2).
 */
enum BlockReason: string
{
    /** RFC 1918, CGNAT 100.64/10, ULA fc00::/7. Freigebbar per cidr oder host. */
    case Private = 'private';
    /** 127/8, ::1. Freigebbar nur per cidr als einzelne Adresse mit Port. */
    case Loopback = 'loopback';
    /** 169.254/16 (Metadaten), fe80::/10. Nie freigebbar. */
    case LinkLocal = 'link_local';
    /** 0/8, ::, Dokumentation, Benchmark, 240/4, eingebettete interne IPv4 u. a. Nie freigebbar. */
    case Reserved = 'reserved';
    /** 224/4, ff00::/8. Nie freigebbar. */
    case Multicast = 'multicast';
    /** Eigene Infrastruktur (ab Phase 4: docker-socket-proxy). Nie freigebbar. */
    case Infrastructure = 'infrastructure';

    public function isReleasable(): bool
    {
        return $this === self::Private || $this === self::Loopback;
    }
}
