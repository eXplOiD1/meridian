<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Die eine Stelle für SSRF vor jeder Verbindung (je Hop): auflösen, **jede** Adresse prüfen, festhalten.
 * Hat ein Name mehrere Adressen und ist eine davon gesperrt, wird das ganze Ziel abgelehnt.
 */
final class TargetGuard
{
    public function __construct(
        private readonly HostResolver $resolver,
        private readonly AddressPolicy $policy,
    ) {
    }

    /**
     * @param int|null $categoryId gespeicherte Kategorie des Jobs aus der Datenbank
     *
     * @throws TargetUnresolvable|TargetBlocked
     */
    public function pin(#[\SensitiveParameter] ParsedUrl $url, ?int $categoryId): PinnedTarget
    {
        $candidates = $url->isIpLiteral ? [$url->host] : $this->resolver->resolve($url->host);

        $ips = [];
        foreach ($candidates as $candidate) {
            $packed = IpNetwork::pack($candidate);
            $canonical = $packed === null ? false : inet_ntop($packed);
            if ($canonical === false) {
                // Unlesbare Antwort des Resolvers: nichts davon benutzen.
                throw new TargetUnresolvable();
            }
            $ips[$canonical] = true;
        }
        $ips = array_keys($ips);
        if ($ips === []) {
            throw new TargetUnresolvable();
        }

        $blocked = null;
        foreach ($ips as $ip) {
            $reason = $this->policy->check($ip, $url->host, $url->port, $categoryId);
            if ($reason !== null && ($blocked === null || !$reason->isReleasable())) {
                // Ein nie freigebbarer Grund hat Vorrang: die Meldung soll keine Freigabe versprechen, die nicht hilft.
                $blocked = $reason;
            }
        }
        if ($blocked !== null) {
            throw new TargetBlocked($blocked);
        }

        return new PinnedTarget($url, $ips);
    }
}
