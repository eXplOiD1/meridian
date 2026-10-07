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
    public function __construct(
        public string $dataDir,
        public string $environment,
        public string $timezone,
        public string $version,
        /** @var list<string> IPs oder Netze (CIDR) von Reverse-Proxys, deren X-Forwarded-For geglaubt wird. */
        public array $trustedProxies = [],
        /** Ordner der gebauten Oberfläche (frontend/dist). Leer = nicht vorhanden. */
        public string $uiDir = '',
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

        return new self(
            dataDir: rtrim($env['MERIDIAN_DATA_DIR'] ?? '/var/lib/meridian', '/'),
            environment: $environment,
            timezone: $timezone,
            version: '0.1.0-dev',
            trustedProxies: $proxies,
            // Im Docker-Image liegt die Oberfläche unter /app/ui; lokal wahlweise MERIDIAN_UI_DIR=frontend/dist.
            uiDir: rtrim($env['MERIDIAN_UI_DIR'] ?? dirname(__DIR__) . '/ui', '/'),
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
