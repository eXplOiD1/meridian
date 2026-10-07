<?php

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Zentrale Rechteprüfung. Jeder Controller und jeder CLI-Befehl ruft sie auf,
 * bevor er Daten liest oder ändert. Standard ist: verboten.
 */
final class AccessControl
{
    /**
     * @param list<RoleGrant> $grants
     */
    public function can(array $grants, Permission $permission, ?string $category = null): bool
    {
        foreach ($grants as $grant) {
            if ($grant->allows($permission, $category)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<RoleGrant> $grants
     *
     * @throws AccessDenied
     */
    public function require(array $grants, Permission $permission, ?string $category = null): void
    {
        if (!$this->can($grants, $permission, $category)) {
            throw new AccessDenied($permission);
        }
    }
}
