<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Http;

/**
 * Netze, in denen der Container selbst hängt: Grundlage für `MERIDIAN_TRUSTED_PROXIES=auto`.
 *
 * Gelesen werden nur die direkt verbundenen Netze aus der Routing-Tabelle des Containers. Aus Sicherheitsgründen gilt
 * `auto` ausschließlich in einem Container und nur für private Docker-Netze (172.16.0.0/12, Präfix 16 bis 30). Ein
 * Heimnetz (192.168.x.x), ein breites Netz (10.0.0.0/8) oder die Standardroute zählen nie, denn wer dort Anfragen
 * stellen kann, könnte sonst `X-Forwarded-For` fälschen und die Sperre nach Fehlversuchen umgehen. Alles andere trägt
 * der Betreiber ausdrücklich ein.
 */
final class OwnNetworks
{
    public const ROUTE_FILE = '/proc/net/route';

    /** Erste 12 Bit von 172.16.0.0/12. */
    private const DOCKER_RANGE_PREFIX = 0xAC1;
    private const MIN_PREFIX = 16;
    private const MAX_PREFIX = 30;

    /**
     * @param bool|null $inContainer null = erkennen (`/.dockerenv` oder `/run/.containerenv`)
     *
     * @return list<string> Netze in CIDR-Schreibweise, leer außerhalb eines Containers oder ohne passendes Netz
     */
    public static function detect(string $routeFile = self::ROUTE_FILE, ?bool $inContainer = null): array
    {
        $inContainer ??= is_file('/.dockerenv') || is_file('/run/.containerenv');
        if (!$inContainer || !is_readable($routeFile)) {
            return [];
        }
        $contents = file_get_contents($routeFile);

        return $contents === false ? [] : self::parse($contents);
    }

    /**
     * @param string $routes Inhalt von /proc/net/route (Adressen als Hex in Netzwerk-Bytefolge, little-endian)
     *
     * @return list<string>
     */
    public static function parse(string $routes): array
    {
        $networks = [];
        foreach (explode("\n", $routes) as $line) {
            $columns = preg_split('/\s+/', trim($line));
            if ($columns === false || count($columns) < 8 || $columns[0] === 'Iface') {
                continue;
            }
            // Nur direkt verbundene Netze (ohne Gateway); die Standardroute hat die Maske 0.
            if ($columns[2] !== '00000000') {
                continue;
            }
            $destination = self::address($columns[1]);
            $mask = self::address($columns[7]);
            if ($destination === null || $mask === null || $mask === 0) {
                continue;
            }
            $inverse = ~$mask & 0xFFFFFFFF;
            if (($inverse & ($inverse + 1)) !== 0) {
                continue; // keine zusammenhängende Maske
            }
            $prefix = substr_count(decbin($mask), '1');
            $network = $destination & $mask;
            if ($prefix < self::MIN_PREFIX || $prefix > self::MAX_PREFIX || ($network >> 20) !== self::DOCKER_RANGE_PREFIX) {
                continue;
            }
            $networks[] = long2ip($network) . '/' . $prefix;
        }

        return array_values(array_unique($networks));
    }

    private static function address(string $hex): ?int
    {
        if (preg_match('/^[0-9A-Fa-f]{8}$/D', $hex) !== 1) {
            return null;
        }
        $value = hexdec(implode('', array_reverse(str_split($hex, 2))));

        return is_int($value) ? $value : null;
    }
}
