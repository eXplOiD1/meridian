<?php

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
