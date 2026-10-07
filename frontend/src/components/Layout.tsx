import { InsecureWarning } from './InsecureWarning';
import type { ReactNode } from 'react';
import { canManageUsers, roleNames } from '../lib/permissions';
import type { Route } from '../lib/useHashRoute';
import { navigate } from '../lib/useHashRoute';
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
  route: Route | null;
  label: string;
  hint?: string;
}

/** Menü wie im Klickdummy. Punkte ohne Bildschirm sind sichtbar, aber noch nicht anwählbar. */
function entries(profile: Profile): NavEntry[] {
  const list: NavEntry[] = [
    { route: '', label: 'Übersicht' },
    { route: null, label: 'Jobs', hint: 'bald' },
    { route: null, label: 'Verlauf', hint: 'bald' },
    { route: null, label: 'Statusseiten', hint: 'bald' },
    { route: null, label: 'Benutzer & Rollen', hint: 'bald' },
  ];
  if (canManageUsers(profile)) {
    list.push({ route: 'audit', label: 'Audit-Log' });
  }
  list.push({ route: 'konto', label: 'Mein Konto' });
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
            entry.route === null ? (
              <span key={entry.label} className="nav__item" aria-disabled="true">
                <span>{entry.label}</span>
                <span className="nav__hint">{entry.hint}</span>
              </span>
            ) : (
              <a
                key={entry.label}
                className="nav__item"
                href={entry.route === '' ? '#/' : '#/' + entry.route}
                aria-current={entry.route === route ? 'page' : undefined}
                onClick={(event) => {
                  event.preventDefault();
                  navigate(entry.route as Route);
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
