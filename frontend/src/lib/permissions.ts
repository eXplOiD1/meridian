import type { Profile } from '../types';

/**
 * Nur Bedienkomfort: Menüpunkte und Schaltflächen ausblenden. Der Server prüft jedes Recht selbst
 * (Rolle und Kategorie aus dem gespeicherten Datensatz) und antwortet sonst mit 403 oder 404.
 * Gefährliche Rechte gelten nur bei ausdrücklich „allen Kategorien“ (categories === null).
 */
export function canManageUsers(profile: Profile): boolean {
  return profile.roles.some((grant) => grant.categories === null && grant.permissions.includes('users.manage'));
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
