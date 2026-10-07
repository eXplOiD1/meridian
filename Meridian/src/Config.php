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
    ) {
    }

    /**
     * @param array<string, string> $env
     */
    public static function fromEnvironment(array $env): self
    {
        $environment = $env['MERIDIAN_ENV'] ?? 'prod';
        if ($environment !== 'prod' && $environment !== 'dev') {
            throw new \InvalidArgumentException('MERIDIAN_ENV muss "prod" oder "dev" sein.');
        }

        $timezone = $env['MERIDIAN_TIMEZONE'] ?? 'Europe/Berlin';
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('MERIDIAN_TIMEZONE ist keine gültige Zeitzone.');
        }

        return new self(
            dataDir: rtrim($env['MERIDIAN_DATA_DIR'] ?? '/var/lib/meridian', '/'),
            environment: $environment,
            timezone: $timezone,
            version: '0.1.0-dev',
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
