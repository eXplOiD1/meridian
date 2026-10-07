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

    /**
     * Die eine Sichtbarkeitsfunktion für Listen, Detail, Verlauf und Lauf: alle Kategorien, in denen
     * can($grants, $permission, $kategorie) gilt. Abgeleitet aus denselben RoleGrant::allows()-Regeln, deshalb
     * gilt für jede Kategorie k (auch null): scope(...)->contains(k) === can(..., k).
     *
     * @param list<RoleGrant> $grants
     */
    public function scope(array $grants, Permission $permission): CategoryScope
    {
        $names = [];
        foreach ($grants as $grant) {
            if ($grant->categories === null) {
                // Uneingeschränkte Zuweisung: gilt sie ohne Kategorie, gilt sie für jede.
                if ($grant->allows($permission, null)) {
                    return CategoryScope::everything();
                }
                continue;
            }
            foreach ($grant->categories as $category) {
                if ($grant->allows($permission, $category)) {
                    $names[] = $category;
                }
            }
        }

        return $names === [] ? CategoryScope::nothing() : CategoryScope::only($names);
    }
}
