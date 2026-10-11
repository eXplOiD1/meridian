<?php

declare(strict_types=1);

namespace Meridian\User;

/**
 * Folgen-Vorschau einer Rolle für Editor und Löschen (ADR 0006 §4, §5), aus Sicht des Handelnden. Nur Anzahlen und
 * Flags, nie Benutzernamen.
 */
final readonly class RoleUsage
{
    public function __construct(
        public Role $role,
        /** Zuweisungen insgesamt (auch inaktive Benutzer; gelöschte haben keine). */
        public int $assignments,
        /** Zuweisungen, die nicht „Alle Kategorien“ gelten (Flag fehlt oder Kategoriezeilen vorhanden). */
        public int $restrictedAssignments,
        /** Aktive, nicht gelöschte Inhaber. */
        public int $activeUsers,
        public bool $heldByActor,
        /** Darf der Handelnde die Rechte grundsätzlich setzen (nicht Admin-Rolle, nicht eigene außer F5)? */
        public bool $permissionsEditable,
    ) {
    }

    /** Löschbar: eigene Rolle ohne Zuweisungen (F4); eine eigene zugewiesene Rolle hat Zuweisungen. */
    public function deletable(): bool
    {
        return !$this->role->isBuiltin() && $this->assignments === 0 && !$this->heldByActor;
    }
}
