import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Alert } from '../components/Alert';
import { Confirm } from '../components/Confirm';
import { FormField } from '../components/FormField';
import { request } from '../lib/api';
import { errorMessage, fieldErrors, isStatus } from '../lib/errors';
import { formatTime } from '../lib/format';
import type { Profile, SessionRow } from '../types';

export function DisplayNameCard({ profile, onChanged }: { profile: Profile; onChanged: () => Promise<void> }) {
  const [name, setName] = useState(profile.user.display_name);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | undefined>(undefined);
  const [done, setDone] = useState(false);

  async function submit(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setFieldError(undefined);
    setDone(false);
    try {
      await request('PUT', '/api/auth/profile', { csrf: profile.csrf_token, body: { display_name: name } });
      setDone(true);
      await onChanged();
    } catch (caught) {
      const named = fieldErrors(caught).display_name;
      setFieldError(named);
      if (named === undefined) {
        setError(errorMessage(caught));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="card" aria-label="Anzeigename" onSubmit={(event) => void submit(event)}>
      <h2>Anzeigename</h2>
      {error !== null && <Alert tone="err">{error}</Alert>}
      {done && <Alert tone="ok">Anzeigename gespeichert.</Alert>}
      <FormField label="Anzeigename" hint="1 bis 64 Zeichen, ohne Leerzeichen am Rand. Der Benutzername bleibt unverändert." error={fieldError}>
        {(aria) => <input {...aria} className="input" name="display_name" autoComplete="name" required value={name} onChange={(e) => setName(e.target.value)} />}
      </FormField>
      <div className="row">
        <button type="submit" className="btn btn--solid" disabled={busy || name === '' || name === profile.user.display_name}>
          Speichern
        </button>
      </div>
    </form>
  );
}

interface PasswordCardProps {
  profile: Profile;
  /** Pflichtwechsel nach Einmalpasswort: andere Überschrift und Beschriftung. */
  forced?: boolean;
  onChanged: () => Promise<void>;
  /** Neues CSRF-Token aus der Antwort sofort übernehmen (die Sitzung ist ersetzt, das alte Token gilt nicht mehr). */
  onCsrfToken: (token: string) => void;
}

/**
 * Passwort ändern. Alle Felder werden nach dem Absenden geleert; das neue CSRF-Token kommt direkt aus der Antwort,
 * danach lädt der Aufrufer das Profil über /me neu.
 */
export function PasswordCard({ profile, forced = false, onChanged, onCsrfToken }: PasswordCardProps) {
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [repeat, setRepeat] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [done, setDone] = useState(false);

  async function submit(event: FormEvent): Promise<void> {
    event.preventDefault();
    setError(null);
    setFields({});
    setDone(false);
    if (next !== repeat) {
      setFields({ repeat: 'Die beiden neuen Passwörter stimmen nicht überein.' });
      return;
    }
    setBusy(true);
    const body = { current_password: current, new_password: next };
    setCurrent('');
    setNext('');
    setRepeat('');
    try {
      const reply = await request<{ csrf_token?: unknown }>('POST', '/api/auth/password', { csrf: profile.csrf_token, body });
      setDone(true);
      // Der Server hat die Sitzung ersetzt: das neue Token sofort übernehmen, dann das Profil neu laden.
      if (typeof reply.csrf_token === 'string' && reply.csrf_token !== '') {
        onCsrfToken(reply.csrf_token);
      }
      await onChanged();
    } catch (caught) {
      const errs = fieldErrors(caught);
      setFields(errs);
      if (Object.keys(errs).length === 0 || isStatus(caught, 403) || isStatus(caught, 429)) {
        setError(errorMessage(caught));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className={forced ? 'form' : 'card'} aria-label="Passwort ändern" onSubmit={(event) => void submit(event)}>
      <h2>{forced ? 'Passwort festlegen' : 'Passwort ändern'}</h2>
      <p className="card__lead">
        {forced
          ? 'Du hast ein Einmalpasswort erhalten. Lege jetzt ein eigenes Passwort fest, bevor du Meridian benutzt.'
          : 'Nach dem Ändern enden alle anderen Sitzungen dieses Kontos.'}
      </p>
      {error !== null && <Alert tone="err">{error}</Alert>}
      {done && !forced && <Alert tone="ok">Passwort geändert. Andere Sitzungen wurden beendet.</Alert>}
      <FormField label={forced ? 'Einmalpasswort' : 'Aktuelles Passwort'} error={fields.current_password}>
        {(aria) => <input {...aria} className="input" type="password" name="current_password" autoComplete="current-password" required value={current} onChange={(e) => setCurrent(e.target.value)} />}
      </FormField>
      <FormField label="Neues Passwort" hint="Mindestens 8 Zeichen." error={fields.new_password}>
        {(aria) => <input {...aria} className="input" type="password" name="new_password" autoComplete="new-password" required value={next} onChange={(e) => setNext(e.target.value)} />}
      </FormField>
      <FormField label="Neues Passwort wiederholen" error={fields.repeat}>
        {(aria) => <input {...aria} className="input" type="password" name="repeat" autoComplete="new-password" required value={repeat} onChange={(e) => setRepeat(e.target.value)} />}
      </FormField>
      <div className="row">
        <button type="submit" className="btn btn--solid" disabled={busy || current === '' || next === '' || repeat === ''}>
          {forced ? 'Passwort festlegen' : 'Passwort ändern'}
        </button>
      </div>
    </form>
  );
}

export function SessionsCard({ profile }: { profile: Profile }) {
  const csrf = profile.csrf_token;
  const [sessions, setSessions] = useState<SessionRow[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);

  const load = useCallback(async (): Promise<void> => {
    try {
      setSessions((await request<{ sessions: SessionRow[] }>('GET', '/api/auth/sessions')).sessions);
    } catch (caught) {
      setError(errorMessage(caught));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function act(action: () => Promise<string>): Promise<void> {
    setBusy(true);
    setError(null);
    setInfo(null);
    try {
      setInfo(await action());
      await load();
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  const others = (sessions ?? []).filter((s) => !s.current).length;

  return (
    <section className="card" aria-label="Sitzungen">
      <h2>Sitzungen</h2>
      <p className="card__lead">Wo dein Konto gerade angemeldet ist. Eine Sitzung, die du nicht erkennst, beende sofort und ändere dein Passwort.</p>
      {error !== null && <Alert tone="err">{error}</Alert>}
      {info !== null && <Alert tone="ok">{info}</Alert>}
      <div className="table-wrap">
        <table className="table">
          <thead>
            <tr>
              <th scope="col">Browser</th>
              <th scope="col">Adresse</th>
              <th scope="col">Angemeldet</th>
              <th scope="col">Zuletzt aktiv</th>
              <th scope="col">Aktion</th>
            </tr>
          </thead>
          <tbody>
            {(sessions ?? []).map((s) => (
              <tr key={s.id}>
                <td className="ua">
                  {s.user_agent ?? <span className="hint">unbekannt</span>}
                  {s.current && (
                    <div className="badges">
                      <span className="badge badge--ok">diese Sitzung</span>
                    </div>
                  )}
                </td>
                <td className="mono">{s.client_ip ?? ''}</td>
                <td className="mono">{formatTime(s.created_at)}</td>
                <td className="mono">{formatTime(s.last_seen_at)}</td>
                <td>
                  {!s.current && (
                    <Confirm
                      label="Beenden"
                      question="Diese Sitzung beenden?"
                      confirmLabel="Beenden"
                      busy={busy}
                      accessibleName={'Sitzung vom ' + formatTime(s.created_at) + ' beenden'}
                      onConfirm={() =>
                        void act(async () => {
                          await request('DELETE', '/api/auth/sessions/' + String(s.id), { csrf });
                          return 'Sitzung beendet.';
                        })
                      }
                    />
                  )}
                </td>
              </tr>
            ))}
            {sessions !== null && sessions.length === 0 && (
              <tr>
                <td colSpan={5} className="hint">
                  Keine Sitzungen.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      <div className="row">
        <Confirm
          label="Alle anderen beenden"
          question="Alle anderen Sitzungen beenden?"
          confirmLabel="Alle anderen beenden"
          busy={busy}
          disabled={others === 0}
          onConfirm={() =>
            void act(async () => {
              await request('POST', '/api/auth/sessions/end-others', { csrf });
              return 'Alle anderen Sitzungen sind beendet.';
            })
          }
        />
      </div>
    </section>
  );
}
