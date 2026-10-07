<?php

declare(strict_types=1);

namespace Meridian\Settings;

/**
 * Die Allowlist der globalen Einstellungen (docs/decisions/0003, E10). Dieselben Schlüssel stehen als CHECK in
 * `settings.key` (Migration 0007); ein neuer Schlüssel braucht deshalb eine neue Migration. Nie Geheimnisse.
 */
enum SettingKey: string
{
    case HttpMaxTimeout = 'http.max_timeout_seconds';
    case HttpResponseStorage = 'http.response_storage';
    case HttpDisplayPath = 'http.display_path';

    /** Standardwert ohne Zeile in `settings`. */
    public function default(): int|string
    {
        return match ($this) {
            self::HttpMaxTimeout => Settings::DEFAULT_MAX_TIMEOUT_SECONDS,
            self::HttpResponseStorage => ResponseStorage::default()->value,
            self::HttpDisplayPath => DisplayPathMode::default()->value,
        };
    }
}
