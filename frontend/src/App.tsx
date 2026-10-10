import { useCallback, useEffect, useState } from 'react';
import { Layout } from './components/Layout';
import { ApiError, PASSWORD_CHANGE_EVENT, request, SESSION_ENDED_EVENT, setCsrfRefresher } from './lib/api';
import { canManageCategories, canManageUsers } from './lib/permissions';
import type { Route } from './lib/useHashRoute';
import { navigate, useHashRoute } from './lib/useHashRoute';
import { Account } from './screens/Account';
import { Audit } from './screens/Audit';
import { Categories } from './screens/Categories';
import { JobDetail } from './screens/JobDetail';
import { JobEdit } from './screens/JobEdit';
import { Jobs } from './screens/Jobs';
import { Login } from './screens/Login';
import { Overview } from './screens/Overview';
import { PasswordCard } from './screens/AccountExtras';
import { UserEdit } from './screens/UserEdit';
import { Roles, Users } from './screens/Users';
import { Settings } from './screens/Settings';
import type { Profile } from './types';

type Session = { status: 'loading' } | { status: 'anonymous' } | { status: 'failed'; message: string } | { status: 'in'; profile: Profile };

const HEADINGS: Record<Route['name'], { kicker: string; title: string }> = {
  home: { kicker: 'Alles auf einen Blick', title: 'Übersicht' },
  audit: { kicker: 'Wer hat wann was getan', title: 'Audit-Log' },
  settings: { kicker: 'Betrieb und Schutz', title: 'Einstellungen' },
  konto: { kicker: 'Anmeldung und Sicherheit', title: 'Mein Konto' },
  jobs: { kicker: 'Zeitgesteuerte Aufgaben', title: 'Jobs' },
  'job-new': { kicker: 'Jobs', title: 'Neuer Job' },
  job: { kicker: 'Jobs', title: 'Job' },
  'job-edit': { kicker: 'Jobs', title: 'Job bearbeiten' },
  users: { kicker: 'Zugang und Rechte', title: 'Benutzer & Rollen' },
  roles: { kicker: 'Zugang und Rechte', title: 'Rollen und Rechte' },
  'user-new': { kicker: 'Benutzer & Rollen', title: 'Benutzer anlegen' },
  user: { kicker: 'Benutzer & Rollen', title: 'Benutzer' },
  categories: { kicker: 'Zugang und Rechte', title: 'Kategorien' },
};

export function App() {
  const [session, setSession] = useState<Session>({ status: 'loading' });
  const route = useHashRoute();

  // Die Sitzung liegt im HttpOnly-Cookie: ob jemand angemeldet ist, erfährt die Seite nur vom Server.
  const refresh = useCallback(async (): Promise<void> => {
    try {
      setSession({ status: 'in', profile: await request<Profile>('GET', '/api/auth/me') });
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setSession({ status: 'anonymous' });
      } else {
        setSession({ status: 'failed', message: error instanceof ApiError ? error.message : 'Unerwarteter Fehler.' });
      }
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  // CSRF-Token veraltet (403 mit csrf_failed): einmal /me neu laden und das neue Token übernehmen. Die abgewiesene
  // Anfrage wiederholt niemand automatisch; die Stelle zeigt „Sitzung aktualisiert, bitte erneut absenden.“
  useEffect(() => {
    setCsrfRefresher(async (): Promise<boolean> => {
      try {
        const profile = await request<Profile>('GET', '/api/auth/me');
        setSession({ status: 'in', profile });
        return true;
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          setSession({ status: 'anonymous' });
        }
        return false;
      }
    });
    return () => setCsrfRefresher(null);
  }, []);

  // Der Server hat die Sitzung beendet (z. B. Passwort per Befehl neu gesetzt): zurück zur Anmeldung statt Fehlermeldungen.
  useEffect(() => {
    const ended = (): void => {
      navigate('');
      setSession({ status: 'anonymous' });
    };
    window.addEventListener(SESSION_ENDED_EVENT, ended);
    return () => window.removeEventListener(SESSION_ENDED_EVENT, ended);
  }, []);

  // Ein Aufruf wurde mit „Passwortwechsel nötig“ abgewiesen (z. B. Admin hat das Passwort zurückgesetzt): Profil neu laden.
  useEffect(() => {
    const required = (): void => {
      void refresh();
    };
    window.addEventListener(PASSWORD_CHANGE_EVENT, required);
    return () => window.removeEventListener(PASSWORD_CHANGE_EVENT, required);
  }, [refresh]);

  // Neues CSRF-Token aus einer Antwort (Passwortwechsel ersetzt die Sitzung) direkt übernehmen.
  const adoptCsrf = useCallback((token: string): void => {
    setSession((current) => (current.status === 'in' ? { status: 'in', profile: { ...current.profile, csrf_token: token } } : current));
  }, []);

  const logout = useCallback(async (profile: Profile): Promise<void> => {
    try {
      await request('POST', '/api/auth/logout', { csrf: profile.csrf_token });
    } finally {
      // Auch wenn der Server nicht antwortet: lokal vergessen, dann neu fragen.
      navigate('');
      setSession({ status: 'anonymous' });
    }
  }, []);

  if (session.status === 'loading') {
    return <div className="center">Lädt …</div>;
  }
  if (session.status === 'failed') {
    return (
      <div className="center">
        <div className="login__card form">
          <p className="alert alert--err" role="alert">
            {session.message}
          </p>
          <button type="button" className="btn btn--solid" onClick={() => void refresh()}>
            Erneut versuchen
          </button>
        </div>
      </div>
    );
  }
  if (session.status === 'anonymous') {
    return <Login onLoggedIn={(profile) => setSession({ status: 'in', profile })} />;
  }

  const { profile } = session;
  // Pflichtwechsel nach Einmalpasswort: nur dieser Schritt und Abmelden, kein Menü, keine anderen Aufrufe.
  if (profile.password_change_required === true) {
    return (
      <div className="center">
        <div className="login__card">
          <PasswordCard profile={profile} forced onChanged={refresh} onCsrfToken={adoptCsrf} />
          <button type="button" className="btn btn--ghost" onClick={() => void logout(profile)}>
            Abmelden
          </button>
        </div>
      </div>
    );
  }
  // Nur Bedienkomfort: Die Seite „Audit-Log“ prüft der Server selbst; ohne Recht antwortet er mit 403.
  const usersRoute = route.name === 'users' || route.name === 'roles' || route.name === 'user-new' || route.name === 'user' || route.name === 'audit';
  const effective: Route =
    (usersRoute && !canManageUsers(profile)) || (route.name === 'categories' && !canManageCategories(profile)) ? { name: 'home' } : route;
  const heading = HEADINGS[effective.name];

  return (
    <Layout profile={profile} route={effective} kicker={heading.kicker} title={heading.title} onLogout={() => void logout(profile)}>
      {effective.name === 'home' && <Overview profile={profile} />}
      {effective.name === 'konto' && <Account profile={profile} onChanged={refresh} onCsrfToken={adoptCsrf} />}
      {effective.name === 'settings' && <Settings profile={profile} />}
      {effective.name === 'audit' && <Audit profile={profile} />}
      {effective.name === 'jobs' && <Jobs profile={profile} />}
      {effective.name === 'job' && <JobDetail key={effective.id} profile={profile} id={effective.id} />}
      {effective.name === 'job-new' && <JobEdit profile={profile} id={null} />}
      {effective.name === 'users' && <Users />}
      {effective.name === 'roles' && <Roles />}
      {effective.name === 'user-new' && <UserEdit key="new" profile={profile} id={null} />}
      {effective.name === 'user' && <UserEdit key={effective.id} profile={profile} id={effective.id} />}
      {effective.name === 'categories' && <Categories profile={profile} />}
      {effective.name === 'job-edit' && <JobEdit key={effective.id} profile={profile} id={effective.id} />}
    </Layout>
  );
}
