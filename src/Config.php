<?php

declare(strict_types=1);

namespace Meridian;

/**
 * Laufzeit-Konfiguration aus Umgebungsvariablen.
 *
 * Geheimnisse (der Hauptschlüssel) stehen NIE hier, sondern werden
 * ausschließlich über {@see Security\KeyLoader} geladen.
 */
final readonly class Config
{
    public const DEFAULT_LISTEN_PORT = 8080;
    public const DEFAULT_DOCKER_PROXY_HOSTS = ['docker-proxy'];

    public function __construct(
        public string $dataDir,
        public string $environment,
        public string $timezone,
        public string $version,
        /** @var list<string> IPs oder Netze (CIDR) von Reverse-Proxys, deren X-Forwarded-For geglaubt wird. */
        public array $trustedProxies = [],
        /** Ordner der gebauten Oberfläche (frontend/dist). Leer = nicht vorhanden. */
        public string $uiDir = '',
        /** Port, auf dem Meridian selbst lauscht (FrankenPHP `:8080`); auf Loopback/eigenen Adressen nie ein HTTP-Ziel. */
        public int $listenPort = self::DEFAULT_LISTEN_PORT,
        /** @var list<string> Host-/Dienstnamen des docker-socket-proxy; nie ein HTTP-Ziel, nie freigebbar. */
        public array $dockerProxyHosts = self::DEFAULT_DOCKER_PROXY_HOSTS,
    ) {
    }

    /**
     * @param array<string, string> $env
     */
    public static function fromEnvironment(#[\SensitiveParameter] array $env): self
    {
        $environment = $env['MERIDIAN_ENV'] ?? 'prod';
        if ($environment !== 'prod' && $environment !== 'dev') {
            throw new \InvalidArgumentException('MERIDIAN_ENV muss "prod" oder "dev" sein.');
        }

        $timezone = $env['MERIDIAN_TIMEZONE'] ?? 'Europe/Berlin';
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('MERIDIAN_TIMEZONE ist keine gültige Zeitzone.');
        }

        $proxies = [];
        foreach (explode(',', $env['MERIDIAN_TRUSTED_PROXIES'] ?? '') as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (preg_match('/^[0-9a-fA-F:.]+(\/[0-9]{1,3})?$/', $entry) !== 1) {
                throw new \InvalidArgumentException('MERIDIAN_TRUSTED_PROXIES: kommagetrennte IP-Adressen oder Netze wie 172.19.0.0/16.');
            }
            $proxies[] = $entry;
        }

        $listenPort = self::DEFAULT_LISTEN_PORT;
        $portText = trim($env['MERIDIAN_LISTEN_PORT'] ?? '');
        if ($portText !== '') {
            if (preg_match('/^[1-9][0-9]{0,4}$/D', $portText) !== 1 || (int) $portText > 65535) {
                throw new \InvalidArgumentException('MERIDIAN_LISTEN_PORT: Port von 1 bis 65535, auf dem Meridian lauscht (Standard 8080).');
            }
            $listenPort = (int) $portText;
        }

        $proxyHosts = self::DEFAULT_DOCKER_PROXY_HOSTS;
        if (isset($env['MERIDIAN_DOCKER_PROXY_HOSTS'])) {
            $proxyHosts = [];
            foreach (explode(',', $env['MERIDIAN_DOCKER_PROXY_HOSTS']) as $entry) {
                $entry = strtolower(trim($entry));
                if ($entry === '') {
                    continue;
                }
                if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/D', $entry) !== 1) {
                    throw new \InvalidArgumentException('MERIDIAN_DOCKER_PROXY_HOSTS: kommagetrennte Hostnamen wie docker-proxy.');
                }
                $proxyHosts[] = $entry;
            }
            // Eine leere Angabe heißt nie „kein Proxy“: der Standardname bleibt immer gesperrt.
            $proxyHosts = array_values(array_unique([...self::DEFAULT_DOCKER_PROXY_HOSTS, ...$proxyHosts]));
        }

        return new self(
            dataDir: rtrim($env['MERIDIAN_DATA_DIR'] ?? '/var/lib/meridian', '/'),
            environment: $environment,
            timezone: $timezone,
            version: '0.1.0-dev',
            trustedProxies: $proxies,
            // Im Docker-Image liegt die Oberfläche unter /app/ui; lokal wahlweise MERIDIAN_UI_DIR=frontend/dist.
            uiDir: rtrim($env['MERIDIAN_UI_DIR'] ?? dirname(__DIR__) . '/ui', '/'),
            listenPort: $listenPort,
            dockerProxyHosts: $proxyHosts,
        );
    }

    public function isDev(): bool
    {
        return $this->environment === 'dev';
    }

    public function databasePath(): string
    {
        return $this->dataDir . '/meridian.sqlite';
    }
}
