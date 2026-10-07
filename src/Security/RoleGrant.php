<?php

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Eine Rolle, die einem Benutzer zugewiesen ist, optional beschränkt auf Kategorien.
 */
final readonly class RoleGrant
{
    /**
     * @param list<Permission>  $permissions
     * @param list<string>|null $categories null = alle Kategorien
     */
    public function __construct(
        public string $role,
        public array $permissions,
        public ?array $categories = null,
    ) {
    }

    public function allows(Permission $permission, ?string $category): bool
    {
        if (!in_array($permission, $this->permissions, true)) {
            return false;
        }

        if ($this->categories === null) {
            return true;
        }

        // Rechte ohne Kategoriebezug (z. B. Benutzerverwaltung) gibt eine
        // kategoriebeschränkte Rolle nie frei.
        return $category !== null && in_array($category, $this->categories, true);
    }
}
