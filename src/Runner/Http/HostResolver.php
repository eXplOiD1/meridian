<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Löst einen Hostnamen in Adressen auf (IPv4 und IPv6). Wirft nie; nicht auflösbar = leere Liste.
 */
interface HostResolver
{
    /**
     * @return list<string> Adressen in Textform, ohne Doppelte
     */
    public function resolve(string $host): array;
}
