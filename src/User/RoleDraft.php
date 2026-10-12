<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Security\Permission;

/**
 * Geprüfte Eingabe zum Anlegen einer Rolle ({@see RoleValidator::validateCreate()}). Keine Geheimnisse.
 */
final readonly class RoleDraft
{
    /**
     * @param list<Permission> $permissions in Katalog-Reihenfolge, ohne Doppelte
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $permissions,
    ) {
    }
}
