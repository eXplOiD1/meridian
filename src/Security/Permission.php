<?php

declare(strict_types=1);

namespace Meridian\Security;

enum Permission: string
{
    case ViewJobs = 'jobs.view';
    case RunJobs = 'jobs.run';
    case EditHttpJobs = 'jobs.edit_http';
    case EditShellJobs = 'jobs.edit_shell';
    case EditStatusPages = 'status_pages.edit';
    case ManageUsers = 'users.manage';
    /** Freigaben interner Ziele für HTTP-Jobs pflegen (lockert den SSRF-Schutz). */
    case ManageInternalTargets = 'network.internal_targets';
    /** Globale Einstellungen ändern (lockern Schutz: Antworten speichern, Anzeige-Pfad, Zeitlimits). */
    case ManageSettings = 'settings.manage';
    /** Kategorien anlegen, umbenennen, löschen (ändert Sichtbarkeit und Freigaben aller betroffenen Benutzer). */
    case ManageCategories = 'categories.manage';

    /**
     * Gefährliche Rechte: Shell-Jobs (über den Docker-Socket praktisch Root), Benutzerverwaltung und alles,
     * was eine Schutzfunktion für alle Kategorien lockert. Eine auf Kategorien beschränkte Rolle bekommt sie
     * nie (RoleGrant::allows()); die Oberfläche kennzeichnet sie besonders.
     */
    public function isDangerous(): bool
    {
        return match ($this) {
            self::EditShellJobs, self::ManageUsers, self::ManageInternalTargets, self::ManageSettings, self::ManageCategories => true,
            self::ViewJobs, self::RunJobs, self::EditHttpJobs, self::EditStatusPages => false,
        };
    }
}
