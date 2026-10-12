<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Notify;

/**
 * Geprüfte Eingabe für einen Kanal. `secret`: null = unverändert lassen, '' = löschen, sonst ersetzen.
 */
final readonly class ChannelDraft
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $name,
        public ChannelType $type,
        public array $config,
        #[\SensitiveParameter] public ?string $secret,
        public bool $isEnabled,
    ) {
    }
}
