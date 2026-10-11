<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Ein HTTP-Job, wie ihn der Runner aus der Datenbank liest: Typ, gespeicherte Kategorie (für die Freigaben),
 * `config_json` (nicht geheim) und `payload_enc` (verschlüsselt). Der Runner entschlüsselt erst selbst.
 */
final readonly class StoredHttpJob
{
    public function __construct(
        public string $type,
        public ?int $categoryId,
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
