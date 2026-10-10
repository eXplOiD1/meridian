import type { Profile } from '../types';

/**
 * Nur Bedienkomfort: Menüpunkte und Schaltflächen ausblenden. Der Server prüft jedes Recht selbst
 * (Rolle und Kategorie aus dem gespeicherten Datensatz) und antwortet sonst mit 403 oder 404.
 * Gefährliche Rechte gelten nur bei ausdrücklich „allen Kategorien“ (categories === null).
 */
export function canManageUsers(profile: Profile): boolean {
  return profile.roles.some((grant) => grant.categories === null && grant.permissions.includes('users.manage'));
}

/** Rollen anlegen, ändern, löschen: gefährlich, nur uneingeschränkt. */
export function canManageRoles(profile: Profile): boolean {
  return profile.roles.some((grant) => grant.categories === null && grant.permissions.includes('roles.manage'));
}

/** Menü „Benutzer & Rollen“: mit Benutzer- oder Rollenverwaltung. */
export function canSeeUsersArea(profile: Profile): boolean {
  return canManageUsers(profile) || canManageRoles(profile);
}

/** Hat der Handelnde das Recht uneingeschränkt (Voraussetzung, es einer Rolle zu geben oder zu nehmen)? */
export function holdsUnrestricted(profile: Profile, permission: string): boolean {
  return profile.roles.some((grant) => grant.categories === null && grant.permissions.includes(permission));
}

export function canManageCategories(profile: Profile): boolean {
  return profile.roles.some((grant) => grant.categories === null && grant.permissions.includes('categories.manage'));
}

/** Deutsche Bezeichnungen der Rechte (Schlüssel und „gefährlich“ kommen vom Server, GET /api/roles). */
export const PERMISSION_LABELS: Record<string, string> = {
  'jobs.view': 'Jobs ansehen',
  'jobs.run': 'Jobs ausführen',
  'jobs.edit_http': 'HTTP-Jobs bearbeiten',
  'jobs.edit_shell': 'Shell-Jobs bearbeiten',
  'status_pages.edit': 'Statusseiten bearbeiten',
  'users.manage': 'Benutzer verwalten, Audit-Log, Sperren aufheben',
  'categories.manage': 'Kategorien verwalten',
  'network.internal_targets': 'Interne Ziele freigeben',
  'roles.manage': 'Rollen und Rechte verwalten',
  'settings.manage': 'Einstellungen ändern',
  'shell.targets': 'Ausführungsorte für Shell-Jobs verwalten',
};

/** Ein Erklärsatz je Recht für den Rollen-Editor. */
export const PERMISSION_HINTS: Record<string, string> = {
  'jobs.view': 'Jobs, Läufe und Verlauf der erlaubten Kategorien ansehen.',
  'jobs.run': 'Jobs von Hand starten und laufende Läufe abbrechen.',
  'jobs.edit_http': 'HTTP-Jobs anlegen, ändern und löschen.',
  'jobs.edit_shell': 'Shell-Jobs anlegen und ändern. Das führt Befehle auf freigegebenen Zielen aus.',
  'status_pages.edit': 'Statusseiten bearbeiten.',
  'users.manage': 'Benutzer anlegen, Rollen zuweisen, Passwörter und 2FA zurücksetzen, Audit-Log lesen.',
  'categories.manage': 'Kategorien anlegen, umbenennen und löschen.',
  'network.internal_targets': 'Interne Netze und Hosts für HTTP-Jobs freigeben. Das lockert den Schutz.',
  'settings.manage': 'Globale Einstellungen ändern.',
  'shell.targets': 'Container und Host-Profile für Shell-Jobs freigeben.',
  'roles.manage': 'Eigene Rollen anlegen, ihre Rechte ändern und sie löschen.',
};

export function permissionLabel(key: string): string {
  return PERMISSION_LABELS[key] ?? key;
}

/** Gefährliche Rechte gelten nur uneingeschränkt (alle Kategorien ausdrücklich). */
export function canManageSettings(profile: Profile): boolean {
  return profile.roles.some((grant) => grant.categories === null && grant.permissions.includes('settings.manage'));
}

export function canManageInternalTargets(profile: Profile): boolean {
  return profile.roles.some((grant) => grant.categories === null && grant.permissions.includes('network.internal_targets'));
}

/**
 * Hat der Benutzer das Recht in dieser Kategorie? `category === undefined` fragt „irgendwo“ (z. B. für den
 * Knopf „Neuen Job anlegen“); `null` ist „ohne Kategorie“, das nur uneingeschränkte Rollen bedienen.
 */
function has(profile: Profile, permission: string, category?: string | null): boolean {
  return profile.roles.some((grant) => {
    if (!grant.permissions.includes(permission)) {
      return false;
    }
    if (grant.categories === null || category === undefined) {
      return grant.categories === null || grant.categories.length > 0;
    }
    return category !== null && grant.categories.includes(category);
  });
}

export function canViewJobs(profile: Profile): boolean {
  return has(profile, 'jobs.view');
}

export function canEditHttp(profile: Profile, category?: string | null): boolean {
  return has(profile, 'jobs.edit_http', category);
}

/** Shell-Jobs anlegen und ändern: gefährlich, nur uneingeschränkt (alle Kategorien ausdrücklich). */
export function canEditShell(profile: Profile): boolean {
  return profile.roles.some((grant) => grant.categories === null && grant.permissions.includes('jobs.edit_shell'));
}

export function canManageShellTargets(profile: Profile): boolean {
  return profile.roles.some((grant) => grant.categories === null && grant.permissions.includes('shell.targets'));
}

export function canRun(profile: Profile, category?: string | null): boolean {
  return has(profile, 'jobs.run', category);
}

/** Nur wenn jede Rolle auf Kategorien beschränkt ist, sieht der Benutzer nicht alles. */
export function isScoped(profile: Profile): boolean {
  return !profile.roles.some((grant) => grant.categories === null && grant.permissions.includes('jobs.view'));
}

export function roleNames(profile: Profile): string {
  return profile.roles.map((grant) => grant.role).join(', ');
}
