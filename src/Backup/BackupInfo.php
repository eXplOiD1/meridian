<?php

declare(strict_types=1);

namespace Meridian\Backup;

final readonly class BackupInfo
{
    public function __construct(
        public string $name,
        /** UTC, aus dem Dateinamen */
        public \DateTimeImmutable $createdAt,
        public int $bytes,
    ) {
    }
}
