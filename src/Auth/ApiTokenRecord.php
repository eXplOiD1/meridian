<?php

declare(strict_types=1);

namespace Meridian\Auth;

/** Ein Zugriffstoken ohne Geheimnis: Name, Rechte-Umfang, Ablauf, letzte Nutzung. Der Hash verlässt die Datenbank nie. */
final readonly class ApiTokenRecord
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $readOnly,
        public ?string $expiresAt,
        public ?string $lastUsedAt,
        public string $createdAt,
    ) {
    }
}
