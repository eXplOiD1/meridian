<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Monitor;

use Meridian\Runner\Http\InvalidUrl;
use Meridian\Runner\Http\ParsedUrl;
use Meridian\Runner\Http\UrlPolicy;

/**
 * Ziel einer aktiven Prüfung (ADR 0018). Dieselbe strenge Syntaxprüfung wie bei HTTP-Jobs ({@see UrlPolicy}): Host-Regeln,
 * gesperrte Namen, keine Zugangsdaten. Tcp und Ping nehmen kein Schema, Pfad oder Query. Die Adressprüfung (SSRF) macht
 * später der `TargetGuard` bei jeder Prüfung, nie hier.
 */
final class MonitorTarget
{
    /**
     * @throws InvalidUrl mit einer Meldung ohne das Ziel
     */
    public static function parse(MonitorKind $kind, #[\SensitiveParameter] string $input, UrlPolicy $urls): ParsedUrl
    {
        $input = trim($input);
        if ($kind === MonitorKind::Http) {
            return $urls->parse($input);
        }
        if ($kind === MonitorKind::Heartbeat) {
            throw new InvalidUrl('Ein Herzschlag hat kein Ziel.');
        }
        if ($input === '' || preg_match('~[/?#@\\\\\s]~', $input) === 1) {
            throw new InvalidUrl($kind === MonitorKind::Tcp ? 'Erwartet wird Host:Port, z. B. db.example.org:5432.' : 'Erwartet wird ein Hostname oder eine IP-Adresse, z. B. router.example.org.');
        }
        if ($kind === MonitorKind::Tcp) {
            if (preg_match('/:([0-9]{1,5})$/D', $input, $m) !== 1 || (int) $m[1] < 1 || (int) $m[1] > 65535) {
                throw new InvalidUrl('Der Port fehlt oder ist ungültig (1 bis 65535). Erwartet wird Host:Port, z. B. db.example.org:5432.');
            }
        } elseif (preg_match('/^(?:\[[0-9a-fA-F:.]+\]|[^:\[\]]+)$/D', $input) !== 1) {
            throw new InvalidUrl('Beim Ping steht nur der Host, ohne Port. Erwartet wird z. B. router.example.org.');
        }
        $parsed = $urls->parse('http://' . $input);
        if ($parsed->path() !== '' || $parsed->query() !== null) {
            throw new InvalidUrl('Das Ziel enthält mehr als Host und Port.');
        }

        return $parsed;
    }

    /** Was verschlüsselt gespeichert wird (nur das, was die Prüfung braucht). */
    public static function stored(MonitorKind $kind, #[\SensitiveParameter] ParsedUrl $url): string
    {
        return match ($kind) {
            MonitorKind::Http => $url->toUrl(),
            MonitorKind::Tcp => $url->hostForUrl() . ':' . $url->port,
            default => $url->hostForUrl(),
        };
    }

    /** Anzeige ohne Pfad und Query: Ursprung bzw. Host:Port. */
    public static function display(MonitorKind $kind, #[\SensitiveParameter] ParsedUrl $url): string
    {
        return match ($kind) {
            MonitorKind::Http => $url->origin(),
            MonitorKind::Tcp => $url->hostForUrl() . ':' . $url->port,
            default => $url->hostForUrl(),
        };
    }
}
