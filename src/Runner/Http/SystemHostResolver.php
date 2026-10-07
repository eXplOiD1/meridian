<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Auflösung über den Resolver des Systems: gethostbynamel() für IPv4 (inklusive /etc/hosts und Docker-DNS),
 * dns_get_record(DNS_AAAA) für IPv6. Ohne eigenes Zeitlimit (Resolver-Optionen im Container, §5.4).
 */
final class SystemHostResolver implements HostResolver
{
    #[\Override]
    public function resolve(string $host): array
    {
        if (IpNetwork::pack($host) !== null) {
            return [$host];
        }

        $ips = [];
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        // dns_get_record warnt bei Fehlern statt zu werfen; die Warnung (mit dem Hostnamen) bleibt im Haus.
        set_error_handler(static fn (): bool => true);
        try {
            $records = dns_get_record($host, DNS_AAAA);
        } finally {
            restore_error_handler();
        }
        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
