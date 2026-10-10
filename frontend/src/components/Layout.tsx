import { InsecureWarning } from './InsecureWarning';
import type { ReactNode } from 'react';
import { canManageCategories, canManageInternalTargets, canManageSettings, canManageUsers, canViewJobs, roleNames } from '../lib/permissions';
import type { Route } from '../lib/useHashRoute';
import { hrefOf, navigate } from '../lib/useHashRoute';
import type { Profile } from '../types';
import { Brand } from './Brand';

interface LayoutProps {
  profile: Profile;
  route: Route;
  kicker: string;
  title: string;
  onLogout: () => void;
  children: ReactNode;
}

interface NavEntry {
  /** Ziel als Pfad ('' = Übersicht); null = noch kein Bildschirm. */
  path: string | null;
  label: string;
  hint?: string;
  current?: (route: Route) => boolean;
}

/** Menü wie im Klickdummy. Punkte ohne Bildschirm sind sichtbar, aber noch nicht anwählbar. */
function entries(profile: Profile): NavEntry[] {
  const list: NavEntry[] = [
    { path: '', label: 'Übersicht', current: (r) => r.name === 'home' },
  ];
  if (canViewJobs(profile)) {
    list.push({ path: 'jobs', label: 'Jobs', current: (r) => r.name === 'jobs' || r.name === 'job-new' || r.name === 'job' || r.name === 'job-edit' });
  } else {
    list.push({ path: null, label: 'Jobs', hint: 'bald' });
  }
  list.push(
    { path: null, label: 'Verlauf', hint: 'bald' },
    { path: null, label: 'Statusseiten', hint: 'bald' },
  );
  if (canManageUsers(profile)) {
    list.push({ path: 'benutzer', label: 'Benutzer & Rollen', current: (r) => r.name === 'users' || r.name === 'roles' || r.name === 'user-new' || r.name === 'user' });
  }
  if (canManageCategories(profile)) {
    list.push({ path: 'kategorien', label: 'Kategorien', current: (r) => r.name === 'categories' });
  }
  if (canManageUsers(profile)) {
    list.push({ path: 'audit', label: 'Audit-Log', current: (r) => r.name === 'audit' });
  }
  if (canManageSettings(profile) || canManageInternalTargets(profile)) {
    list.push({ path: 'einstellungen', label: 'Einstellungen', current: (r) => r.name === 'settings' });
  }
  list.push({ path: 'konto', label: 'Mein Konto', current: (r) => r.name === 'konto' });
  return list;
}

export function Layout({ profile, route, kicker, title, onLogout, children }: LayoutProps) {
  const initial = profile.user.display_name.trim().charAt(0).toUpperCase() || '?';
  return (
    <div className="shell">
      <nav className="sidebar" aria-label="Hauptnavigation">
        <Brand />
        <div className="nav">
          {entries(profile).map((entry) =>
            entry.path === null ? (
              <span key={entry.label} className="nav__item" aria-disabled="true">
                <span>{entry.label}</span>
                <span className="nav__hint">{entry.hint}</span>
              </span>
            ) : (
              <a
                key={entry.label}
                className="nav__item"
                href={hrefOf(entry.path)}
                aria-current={entry.current?.(route) === true ? 'page' : undefined}
                onClick={(event) => {
                  event.preventDefault();
                  navigate(entry.path ?? '');
                }}
              >
                <span>{entry.label}</span>
              </a>
            ),
          )}
        </div>
        <div className="userbox">
          <div className="avatar" aria-hidden="true">
            {initial}
          </div>
          <div className="userbox__text">
            <span className="userbox__name">{profile.user.display_name}</span>
            <span className="userbox__role">{roleNames(profile)}</span>
          </div>
          <button type="button" className="btn btn--ghost btn--on-dark btn--small userbox__logout" onClick={onLogout}>
            Abmelden
          </button>
        </div>
      </nav>
      <main className="main">
        <header className="page-head">
          <div>
            <span className="page-head__kicker">{kicker}</span>
            <h1>{title}</h1>
          </div>
        </header>
        <InsecureWarning />
        {children}
      </main>
    </div>
  );
}
