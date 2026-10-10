<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Ein Shell-Job, wie ihn der Runner bei jedem Lauf frisch liest: Typ, **gespeicherte** Kategorie (für die
 * Freigaben), Zeitzone, `config_json` (nicht geheim) und `payload_enc` (verschlüsselt, entschlüsselt erst der Runner).
 */
final readonly class StoredShellJob
{
    public function __construct(
        public string $type,
        public ?int $categoryId,
        public string $timezone,
        public string $configJson,
        public string $payloadEnc,
    ) {
    }

    /**
     * @return array<string, string|int|null>
     */
    public function __debugInfo(): array
    {
        return ['type' => $this->type, 'category_id' => $this->categoryId];
    }
}
