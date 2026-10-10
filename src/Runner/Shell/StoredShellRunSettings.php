<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Settings\Settings;

/**
 * {@see ShellRunSettings} aus der Tabelle `settings` über {@see Settings}. Liest bei jedem Aufruf frisch.
 */
final class StoredShellRunSettings implements ShellRunSettings
{
    public function __construct(private readonly Settings $settings)
    {
    }

    #[\Override]
    public function maxTimeoutSeconds(): int
    {
        return $this->settings->shellMaxTimeoutSeconds();
    }
}
