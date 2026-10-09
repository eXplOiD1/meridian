<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Settings\DisplayHostMode;
use Meridian\Settings\ResponseStorage;
use Meridian\Settings\Settings;

/**
 * {@see HttpRunSettings} aus der Tabelle `settings` über {@see Settings} (Standardwerte und Prüfung an einer Stelle).
 * Liest bei jedem Aufruf frisch.
 */
final class StoredHttpRunSettings implements HttpRunSettings
{
    public function __construct(private readonly Settings $settings)
    {
    }

    #[\Override]
    public function maxTimeoutSeconds(): int
    {
        return $this->settings->maxTimeoutSeconds();
    }

    #[\Override]
    public function responseStorage(): ResponseStorage
    {
        return $this->settings->responseStorage();
    }

    #[\Override]
    public function hidesHost(): bool
    {
        return $this->settings->displayHost() === DisplayHostMode::Hidden;
    }
}
