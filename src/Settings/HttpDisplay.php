<?php

declare(strict_types=1);

namespace Meridian\Settings;

/**
 * Die aktuell gültigen Anzeige-Einstellungen eines HTTP-Jobs (`http.display_path`, `http.display_host`), einmal je
 * Anfrage aus {@see Settings::httpDisplay()} gelesen. Nie Geheimnisse.
 */
final readonly class HttpDisplay
{
    public function __construct(
        public DisplayPathMode $path,
        public DisplayHostMode $host,
    ) {
    }

    /** Die Standardwerte ohne Zeile in `settings`. */
    public static function defaults(): self
    {
        return new self(DisplayPathMode::default(), DisplayHostMode::default());
    }

    public function hidesHost(): bool
    {
        return $this->host === DisplayHostMode::Hidden;
    }
}
