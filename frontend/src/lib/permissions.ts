import type { Profile } from '../types';

/**
 * Nur Bedienkomfort: Menüpunkte ausblenden. Der Server prüft jedes Recht selbst.
 * Gefährliche Rechte gelten nur bei ausdrücklich „allen Kategorien“ (categories === null).
 */
export function canManageUsers(profile: Profile): boolean {
  return profile.roles.some((grant) => grant.categories === null && grant.permissions.includes('users.manage'));
}

export function roleNames(profile: Profile): string {
  return profile.roles.map((grant) => grant.role).join(', ');
}
