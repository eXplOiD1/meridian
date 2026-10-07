import { useCallback, useEffect, useState } from 'react';
import { Layout } from './components/Layout';
import { ApiError, request, SESSION_ENDED_EVENT } from './lib/api';
import { canManageUsers } from './lib/permissions';
import type { Route } from './lib/useHashRoute';
import { navigate, useHashRoute } from './lib/useHashRoute';
import { Account } from './screens/Account';
import { Audit } from './screens/Audit';
import { Jobs } from './screens/Jobs';
import { Login } from './screens/Login';
import { Overview } from './screens/Overview';
import type { Profile } from './types';

type Session = { status: 'loading' } | { status: 'anonymous' } | { status: 'failed'; message: string } | { status: 'in'; profile: Profile };

const HEADINGS: Record<Route['name'], { kicker: string; title: string }> = {
  home: { kicker: 'Alles auf einen Blick', title: 'Übersicht' },
  audit: { kicker: 'Wer hat wann was getan', title: 'Audit-Log' },
  konto: { kicker: 'Anmeldung und Sicherheit', title: 'Mein Konto' },
  jobs: { kicker: 'Zeitgesteuerte Aufgaben', title: 'Jobs' },
  'job-new': { kicker: 'Jobs', title: 'Neuer Job' },
  job: { kicker: 'Jobs', title: 'Job' },
  'job-edit': { kicker: 'Jobs', title: 'Job bearbeiten' },
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

  // Der Server hat die Sitzung beendet (z. B. Passwort per Befehl neu gesetzt): zurück zur Anmeldung statt Fehlermeldungen.
  useEffect(() => {
    const ended = (): void => {
      navigate('');
      setSession({ status: 'anonymous' });
    };
    window.addEventListener(SESSION_ENDED_EVENT, ended);
    return () => window.removeEventListener(SESSION_ENDED_EVENT, ended);
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
  // Nur Bedienkomfort: Die Seite „Audit-Log“ prüft der Server selbst; ohne Recht antwortet er mit 403.
  const effective: Route = route.name === 'audit' && !canManageUsers(profile) ? { name: 'home' } : route;
  const heading = HEADINGS[effective.name];

  return (
    <Layout profile={profile} route={effective} kicker={heading.kicker} title={heading.title} onLogout={() => void logout(profile)}>
      {effective.name === 'home' && <Overview profile={profile} />}
      {effective.name === 'konto' && <Account profile={profile} onChanged={refresh} />}
      {effective.name === 'audit' && <Audit profile={profile} />}
      {effective.name === 'jobs' && <Jobs profile={profile} />}
    </Layout>
  );
}
