<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Config;

/**
 * Meridians eigene Infrastruktur: nie Ziel eines HTTP-Jobs, auch nicht mit Freigabe (docs/decisions/0003, E5;
 * 0004, E11; Entscheidung Alex 09.10.2026). {@see AddressPolicy::check()} meldet sie als
 * {@see BlockReason::Infrastructure}.
 *
 * - **docker-socket-proxy:** die Docker-API-Ports 2375/2376 auf jeder internen Adresse (privat, Loopback) und die
 *   konfigurierten Host-/Dienstnamen des Proxys (`MERIDIAN_DOCKER_PROXY_HOSTS`, immer mit `docker-proxy`) auf
 *   jedem Port und jeder Adresse. Sonst würde ein HTTP-Job zum Shell-Job.
 * - **Meridian selbst:** der eigene Listen-Port (`MERIDIAN_LISTEN_PORT`, Standard 8080) auf Loopback und auf jeder
 *   Adresse dieses Rechners (Netzwerkschnittstellen), damit kein Job die eigene API über die Vertrauensstellung des
 *   Servers anspricht. Restliches Loopback bleibt nur einzeln mit Port freigebbar ({@see InternalTarget}).
 */
final class InfrastructureTargets
{
    /** Docker-API (unverschlüsselt/TLS). */
    public const DOCKER_PORTS = [2375, 2376];

    /** @var list<string>|null gepackte Adressen der eigenen Schnittstellen; null = beim ersten Bedarf ermitteln */
    private ?array $selfAddresses;

    /**
     * @param list<string>      $dockerProxyHosts Hostnamen in Kleinbuchstaben
     * @param list<string>|null $selfAddresses    eigene Adressen (Textform); null = aus den Netzwerkschnittstellen
     */
    public function __construct(
        public readonly int $listenPort = Config::DEFAULT_LISTEN_PORT,
        private readonly array $dockerProxyHosts = Config::DEFAULT_DOCKER_PROXY_HOSTS,
        ?array $selfAddresses = null,
    ) {
        $this->selfAddresses = $selfAddresses === null ? null : self::packAll($selfAddresses);
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->listenPort, $config->dockerProxyHosts);
    }

    /**
     * Ist dieses Ziel Infrastruktur? Unabhängig von Freigaben.
     *
     * @param string           $packedIp gepackte Adresse, zu der verbunden würde
     * @param BlockReason|null $class    Klasse der Adresse ohne Freigaben (null = öffentlich)
     * @param string           $host     Host des aktuellen Hops (Kleinbuchstaben)
     */
    public function blocks(string $packedIp, ?BlockReason $class, string $host, int $port): bool
    {
        if ($this->isDockerProxyHost($host)) {
            return true;
        }
        if ($class !== null && in_array($port, self::DOCKER_PORTS, true)) {
            return true;
        }

        return $port === $this->listenPort
            && ($class === BlockReason::Loopback || in_array($packedIp, $this->selfAddresses(), true));
    }

    public function isDockerProxyHost(string $host): bool
    {
        return in_array(strtolower($host), $this->dockerProxyHosts, true);
    }

    /**
     * @return list<string>
     */
    private function selfAddresses(): array
    {
        if ($this->selfAddresses === null) {
            $found = [];
            $interfaces = function_exists('net_get_interfaces') ? net_get_interfaces() : false;
            foreach ($interfaces === false ? [] : $interfaces as $interface) {
                $found = [...$found, ...self::unicastAddresses($interface)];
            }
            $this->selfAddresses = self::packAll($found);
        }

        return $this->selfAddresses;
    }

    /**
     * @return list<string> Adressen (Textform) einer Schnittstelle aus net_get_interfaces()
     */
    private static function unicastAddresses(mixed $interface): array
    {
        /** @var mixed $unicast */
        $unicast = is_array($interface) ? ($interface['unicast'] ?? null) : null;
        $found = [];
        /** @var mixed $entry */
        foreach (is_array($unicast) ? $unicast : [] as $entry) {
            if (is_array($entry) && isset($entry['address']) && is_string($entry['address'])) {
                $found[] = $entry['address'];
            }
        }

        return $found;
    }

    /**
     * @param list<string> $addresses
     *
     * @return list<string>
     */
    private static function packAll(array $addresses): array
    {
        $packed = [];
        foreach ($addresses as $address) {
            // IPv6 mit Zone (fe80::1%eth0): Zone abschneiden; ungültige Einträge ignorieren.
            $bytes = IpNetwork::pack(explode('%', $address)[0]);
            if ($bytes !== null) {
                $packed[] = $bytes;
            }
        }

        return array_values(array_unique($packed));
    }
}
