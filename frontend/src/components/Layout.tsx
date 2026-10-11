import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { canManageCategories, canManageInternalTargets, canManageSettings, canManageShellTargets, canEditStatusPages, canManageUsers, canSeeUsersArea, canViewJobs, roleNames } from '../lib/permissions';
import type { Route } from '../lib/useHashRoute';
import { hrefOf, navigate } from '../lib/useHashRoute';
import type { Profile } from '../types';
import { Brand } from './Brand';
import { InsecureWarning } from './InsecureWarning';
import { LanguageSwitch } from './LanguageSwitch';
import { ThemeSwitch } from './ThemeSwitch';
import { t, useLang } from '../i18n';

interface LayoutProps {
  profile: Profile;
  route: Route;
  kicker: string;
  title: string;
  onLogout: () => void;
  children: ReactNode;
}

interface NavEntry {
  /** Ziel als Pfad ('' = Übersicht); null = nicht freigegeben (sichtbar, nicht anwählbar). */
  path: string | null;
  label: string;
  icon: string;
  hint?: string;
  current?: (route: Route) => boolean;
}

interface NavGroup {
  label: string;
  items: NavEntry[];
}

const ICONS: Record<string, string> = {
  home: 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
  jobs: 'M4 6h16M4 12h16M4 18h10',
  history: 'M3 12a9 9 0 1 0 3-6.7M3 4v5h5M12 7v5l3 2',
  stats: 'M5 20V10M12 20V4M19 20v-7',
  monitor: 'M3 12h4l3-8 4 16 3-8h4',
  status: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z',
  users: 'M16 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M9.5 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8M21 20v-2a4 4 0 0 0-3-3.9M16 2.1a4 4 0 0 1 0 7.8',
  folder: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
  audit: 'M7 3h8l4 4v14H7zM15 3v4h4M10 12h6M10 16h6',
  settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
  account: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
};

/** Menü in vier Gruppen (Betrieb, Beobachten, Verwaltung, Konto). Bereiche ohne Recht bleiben sichtbar, aber nicht anwählbar. */
function groups(profile: Profile): NavGroup[] {
  const jobs = canViewJobs(profile);
  const operate: NavEntry[] = [
    { path: '', label: 'Übersicht', icon: 'home', current: (r) => r.name === 'home' },
    jobs
      ? { path: 'jobs', label: 'Jobs', icon: 'jobs', current: (r) => r.name === 'jobs' || r.name === 'job-new' || r.name === 'job' || r.name === 'job-edit' }
      : { path: null, label: 'Jobs', icon: 'jobs', hint: 'bald' },
    jobs ? { path: 'verlauf', label: 'Verlauf', icon: 'history', current: (r) => r.name === 'history' } : { path: null, label: 'Verlauf', icon: 'history', hint: 'bald' },
  ];
  const observe: NavEntry[] = [
    jobs ? { path: 'statistik', label: 'Statistik', icon: 'stats', current: (r) => r.name === 'stats' } : { path: null, label: 'Statistik', icon: 'stats', hint: 'bald' },
    jobs ? { path: 'ueberwachung', label: 'Überwachung', icon: 'monitor', current: (r) => r.name === 'monitors' } : { path: null, label: 'Überwachung', icon: 'monitor', hint: 'bald' },
    canEditStatusPages(profile)
      ? { path: 'statusseiten', label: 'Statusseiten', icon: 'status', current: (r) => r.name === 'status-pages' }
      : { path: null, label: 'Statusseiten', icon: 'status', hint: 'bald' },
  ];
  const admin: NavEntry[] = [];
  if (canSeeUsersArea(profile)) {
    admin.push({
      path: canManageUsers(profile) ? 'benutzer' : 'benutzer/rollen',
      label: 'Benutzer & Rollen',
      icon: 'users',
      current: (r) => r.name === 'users' || r.name === 'roles' || r.name === 'role-new' || r.name === 'role' || r.name === 'user-new' || r.name === 'user',
    });
  }
  if (canManageCategories(profile)) {
    admin.push({ path: 'kategorien', label: 'Kategorien', icon: 'folder', current: (r) => r.name === 'categories' });
  }
  if (canManageUsers(profile)) {
    admin.push({ path: 'audit', label: 'Audit-Log', icon: 'audit', current: (r) => r.name === 'audit' });
  }
  if (canManageSettings(profile) || canManageInternalTargets(profile) || canManageShellTargets(profile)) {
    admin.push({ path: 'einstellungen', label: 'Einstellungen', icon: 'settings', current: (r) => r.name === 'settings' });
  }
  const result: NavGroup[] = [
    { label: 'Betrieb', items: operate },
    { label: 'Beobachten', items: observe },
  ];
  if (admin.length > 0) {
    result.push({ label: 'Verwaltung', items: admin });
  }
  result.push({ label: 'Konto', items: [{ path: 'konto', label: 'Mein Konto', icon: 'account', current: (r) => r.name === 'konto' }] });
  return result;
}

function Icon({ name }: { name: string }) {
  return (
    <svg className="nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d={ICONS[name] ?? ''} />
    </svg>
  );
}

const FOCUSABLE = 'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])';

export function Layout({ profile, route, kicker, title, onLogout, children }: LayoutProps) {
  useLang();
  const [open, setOpen] = useState(false);
  const sidebarRef = useRef<HTMLElement>(null);
  const menuRef = useRef<HTMLButtonElement>(null);
  const workspaceRef = useRef<HTMLDivElement>(null);
  const initial = profile.user.display_name.trim().charAt(0).toUpperCase() || '?';

  const close = useCallback((restoreFocus: boolean) => {
    setOpen(false);
    if (restoreFocus) {
      menuRef.current?.focus();
    }
  }, []);

  // Seitenwechsel schließt die Schublade und setzt den Arbeitsbereich nach oben.
  useEffect(() => {
    setOpen(false);
    workspaceRef.current?.querySelector('.main')?.scrollTo?.({ top: 0 });
    window.scrollTo?.({ top: 0 });
  }, [route.name]);

  // Offene Schublade: Fokus hinein, Tab bleibt darin, Escape schließt, der Hintergrund scrollt nicht.
  useEffect(() => {
    if (!open) {
      return undefined;
    }
    const sidebar = sidebarRef.current;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    sidebar?.querySelector<HTMLElement>('a[aria-current="page"], a[href]')?.focus();
    function onKey(event: KeyboardEvent): void {
      if (event.key === 'Escape') {
        close(true);
        return;
      }
      if (event.key !== 'Tab' || sidebar === null) {
        return;
      }
      const items = Array.from(sidebar.querySelectorAll<HTMLElement>(FOCUSABLE));
      const first = items[0];
      const last = items[items.length - 1];
      if (first === undefined || last === undefined) {
        return;
      }
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = previousOverflow;
    };
  }, [open, close]);

  // Wird das Fenster breit, gibt es keine Schublade mehr.
  useEffect(() => {
    const query = window.matchMedia('(min-width: 768px)');
    const onChange = (): void => {
      if (query.matches) {
        setOpen(false);
      }
    };
    query.addEventListener('change', onChange);
    return () => query.removeEventListener('change', onChange);
  }, []);

  return (
    <div className={`shell${open ? ' is-open' : ''}`}>
      <header className="mobilebar">
        <button
          ref={menuRef}
          type="button"
          className="mobilebar__menu"
          aria-label={t('Menü öffnen')}
          aria-expanded={open}
          aria-controls="hauptnavigation"
          onClick={() => setOpen((value) => !value)}
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
        </button>
        <Brand />
        <div className="mobilebar__tools">
          <LanguageSwitch csrf={profile.csrf_token} />
        </div>
      </header>
      <button type="button" className="drawer-backdrop" tabIndex={-1} aria-label={t('Menü schließen')} onClick={() => close(true)} />
      <nav id="hauptnavigation" ref={sidebarRef} className="sidebar" aria-label={t('Hauptnavigation')}>
        <div className="nav__brand-desktop">
          <Brand />
        </div>
        <div className="nav">
          {groups(profile).map((group) => (
            <div key={group.label} className="nav__group" role="group" aria-label={t(group.label)}>
              <span className="nav__label" aria-hidden="true">{t(group.label)}</span>
              {group.items.map((entry) =>
                entry.path === null ? (
                  <span key={entry.label} className="nav__item" aria-disabled="true">
                    <span><Icon name={entry.icon} />{t(entry.label)}</span>
                    <span className="nav__hint">{entry.hint === undefined ? '' : t(entry.hint)}</span>
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
                      setOpen(false);
                    }}
                  >
                    <span><Icon name={entry.icon} />{t(entry.label)}</span>
                  </a>
                ),
              )}
            </div>
          ))}
        </div>
        <div className="userbox">
          <div className="userbox__who">
            <div className="avatar" aria-hidden="true">{initial}</div>
            <div className="userbox__text">
              <span className="userbox__name">{profile.user.display_name}</span>
              <span className="userbox__role">{roleNames(profile)}</span>
            </div>
          </div>
          <div className="userbox__actions">
            <ThemeSwitch csrf={profile.csrf_token} />
            <button type="button" className="btn btn--ghost btn--small userbox__logout" onClick={onLogout}>
              {t('Abmelden')}
            </button>
          </div>
        </div>
      </nav>
      <div className="workspace" ref={workspaceRef}>
        <main className="main">
          <header className="page-head">
            <div>
              <span className="page-head__kicker">{t(kicker)}</span>
              <h1>{t(title)}</h1>
            </div>
            <div className="page-head__tools">
              <LanguageSwitch csrf={profile.csrf_token} />
            </div>
          </header>
          <InsecureWarning />
          {children}
        </main>
      </div>
    </div>
  );
}
