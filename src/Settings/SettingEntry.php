<?php

declare(strict_types=1);

namespace Meridian\Settings;

/**
 * Eine Einstellung für die Admin-Ansicht: gültiger Wert, Standard und Herkunft. Nie ein Geheimnis (Allowlist E10).
 */
final readonly class SettingEntry
{
    /**
     * @param bool        $stored    true: Wert aus einer gültigen Zeile in `settings`; false: Standard aus dem Code
     * @param string|null $updatedAt nur bei gespeichertem Wert
     * @param string|null $updatedBy Anzeigename; null bei Standard, gelöschtem Benutzer oder Änderung per CLI
     */
    public function __construct(
        public SettingKey $key,
        public int|string $value,
        public bool $stored,
        public ?string $updatedAt,
        public ?string $updatedBy,
    ) {
    }

    /** `stored` oder `default` (Feld `source` der API). */
    public function source(): string
    {
        return $this->stored ? 'stored' : 'default';
    }
}
