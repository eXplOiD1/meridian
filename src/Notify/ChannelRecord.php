<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Notify;

/**
 * Ein Kanal ohne Geheimnis: `config` enthält nur nicht geheime Felder, ob ein Passwort/Token gespeichert ist, sagt nur `hasSecret`.
 */
final readonly class ChannelRecord
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public int $id,
        public string $name,
        public ChannelType $type,
        public array $config,
        public bool $hasSecret,
        public bool $isEnabled,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
