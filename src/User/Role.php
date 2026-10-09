<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Security\Permission;

/**
 * Eine Rolle mit ihren Rechten, wie sie in `roles`/`role_permissions` steht (im MVP nur die drei Standardrollen).
 */
final readonly class Role
{
    /**
     * @param list<Permission> $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $builtin,
        public array $permissions,
    ) {
    }

    /** Mindestens ein gefährliches Recht: nur mit „alle Kategorien“ zuweisbar, Bestätigung mit Passwort (E1, E7). */
    public function isDangerous(): bool
    {
        foreach ($this->permissions as $permission) {
            if ($permission->isDangerous()) {
                return true;
            }
        }

        return false;
    }
}
