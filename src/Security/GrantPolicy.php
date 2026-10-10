<?php

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Schutz gegen Rechteausweitung bei der Benutzerverwaltung (ADR 0005, E4).
 *
 * Beide Prüfungen bauen nur auf {@see AccessControl::can()} auf, nie auf Rollennamen: so gelten sie auch für
 * spätere eigene Rollen. Standard ist verboten — was sich nicht als „hat der Handelnde selbst“ nachweisen lässt,
 * wird abgelehnt.
 */
final class GrantPolicy
{
    public function __construct(private readonly AccessControl $access)
    {
    }

    /**
     * Darf der Handelnde diese Zuweisung vergeben? Jedes Recht der Rolle muss er selbst besitzen, und zwar
     * uneingeschränkt bei „alle Kategorien“ (`$categoryNames === null`), sonst in **jeder** genannten Kategorie.
     * Eine leere Kategorieliste ist keine gültige Zuweisung (sie hieße nie „alle“) und wird abgelehnt.
     *
     * @param list<RoleGrant>   $actorGrants      frisch geladene Rechte des Handelnden
     * @param list<Permission>  $rolePermissions  Rechte der zu vergebenden Rolle
     * @param list<string>|null $categoryNames    null = alle Kategorien
     */
    public function mayAssign(array $actorGrants, array $rolePermissions, ?array $categoryNames): bool
    {
        if ($categoryNames === []) {
            return false;
        }

        foreach ($rolePermissions as $permission) {
            foreach ($categoryNames ?? [null] as $category) {
                if (!$this->access->can($actorGrants, $permission, $category)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Darf der Handelnde einen Benutzer mit diesen Rechten verwalten? Das Ziel darf nichts haben, was dem
     * Handelnden fehlt: für jede Zuweisung des Ziels, jedes ihrer Rechte und jeden Geltungsbereich, in dem sie es
     * tatsächlich gewährt (uneingeschränkt bzw. je Kategorie), muss es auch beim Handelnden gelten.
     *
     * @param list<RoleGrant> $actorGrants
     * @param list<RoleGrant> $targetGrants
     */
    public function mayManage(array $actorGrants, array $targetGrants): bool
    {
        foreach ($targetGrants as $grant) {
            foreach ($grant->permissions as $permission) {
                foreach ($grant->categories ?? [null] as $category) {
                    if ($grant->allows($permission, $category) && !$this->access->can($actorGrants, $permission, $category)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Verlangt `users.manage` ohne Kategorie (über {@see AccessControl::require()}). Die Benutzerverwaltung prüft das
     * am Anfang jeder Schreibtransaktion noch einmal mit frisch geladenen Rechten (Review 4a N1).
     *
     * @param list<RoleGrant> $actorGrants
     *
     * @throws AccessDenied
     */
    public function requireUserManager(array $actorGrants): void
    {
        $this->access->require($actorGrants, Permission::ManageUsers);
    }
}
