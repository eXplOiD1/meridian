import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Alert } from '../components/Alert';
import { Field } from '../components/Field';
import { ApiError, request } from '../lib/api';
import { describeAction, formatTime, retryHint } from '../lib/format';
import type { AuditEntry, AuditPage, Profile } from '../types';

const PAGE_SIZE = 50;
const ACTION_PATTERN = /^[a-z0-9_.]{1,64}$/;

function message(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message + (error.status === 429 ? retryHint(error.retryAfter) : '');
  }
  return 'Unerwarteter Fehler.';
}

/** Sperre aufheben: Benutzername oder IP-Adresse. Der Server prüft Recht, CSRF und Eingabe selbst. */
function Unlock({ csrf }: { csrf: string }) {
  const [target, setTarget] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  async function submit(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setDone(null);
    const value = target.trim();
    // IPv4 und IPv6 enthalten nur Ziffern, Hexzeichen, Punkte und Doppelpunkte; Benutzernamen nie einen Doppelpunkt.
    const isIp = /^[0-9a-fA-F:.]+$/.test(value) && (value.includes(':') || /^\d{1,3}(\.\d{1,3}){3}$/.test(value));
    try {
      await request('POST', '/api/users/unlock', { csrf, body: isIp ? { ip: value } : { username: value } });
      setDone('Die Sperre für „' + value + '“ ist aufgehoben.');
      setTarget('');
    } catch (caught) {
      setError(message(caught));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card" aria-label="Sperre aufheben">
      <h2>Sperre aufheben</h2>
      <p className="card__lead">Nach mehreren Fehlversuchen sperrt Meridian ein Konto oder eine IP-Adresse zeitweise. Hier hebst du die Sperre sofort auf.</p>
      <form className="form" onSubmit={(event) => void submit(event)}>
        {error !== null && <Alert tone="err">{error}</Alert>}
        {done !== null && <Alert tone="ok">{done}</Alert>}
        <Field label="Benutzername oder IP-Adresse" name="target" required autoComplete="off" value={target} onChange={(e) => setTarget(e.target.value)} />
        <div className="row">
          <button type="submit" className="btn btn--solid" disabled={busy || target.trim() === ''}>
            Sperre aufheben
          </button>
        </div>
      </form>
    </section>
  );
}

export function Audit({ profile }: { profile: Profile }) {
  const [entries, setEntries] = useState<AuditEntry[]>([]);
  const [next, setNext] = useState<number | null>(null);
  const [filter, setFilter] = useState('');
  const [applied, setApplied] = useState('');
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (action: string, before: number | null, append: boolean): Promise<void> => {
    setBusy(true);
    setError(null);
    const query = new URLSearchParams({ limit: String(PAGE_SIZE) });
    if (action !== '') {
      query.set('action', action);
    }
    if (before !== null) {
      query.set('before_id', String(before));
    }
    try {
      const page = await request<AuditPage>('GET', '/api/audit?' + query.toString());
      setEntries((current) => (append ? [...current, ...page.entries] : page.entries));
      setNext(page.next_before_id);
    } catch (caught) {
      setError(message(caught));
    } finally {
      setBusy(false);
    }
  }, []);

  useEffect(() => {
    void load('', null, false);
  }, [load]);

  function apply(event: FormEvent): void {
    event.preventDefault();
    const value = filter.trim();
    if (value !== '' && !ACTION_PATTERN.test(value)) {
      setError('Filter: nur Kleinbuchstaben, Ziffern, Punkt und Unterstrich (z. B. auth.login_failed).');
      return;
    }
    setApplied(value);
    void load(value, null, false);
  }

  return (
    <>
      <section className="card" aria-label="Audit-Log">
        <div className="row">
          <h2>Ereignisse</h2>
        </div>
        <form className="row" onSubmit={apply}>
          <div className="row__grow">
            <Field label="Aktion filtern" name="filter" autoComplete="off" mono placeholder="z. B. auth.login_failed" value={filter} onChange={(e) => setFilter(e.target.value)} />
          </div>
          <button type="submit" className="btn btn--ghost" disabled={busy}>
            Filtern
          </button>
          {applied !== '' && (
            <button
              type="button"
              className="btn btn--ghost"
              disabled={busy}
              onClick={() => {
                setFilter('');
                setApplied('');
                void load('', null, false);
              }}
            >
              Zurücksetzen
            </button>
          )}
        </form>
        {error !== null && <Alert tone="err">{error}</Alert>}
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th scope="col">Zeit</th>
                <th scope="col">Ereignis</th>
                <th scope="col">Benutzer</th>
                <th scope="col">Ziel</th>
              </tr>
            </thead>
            <tbody>
              {entries.map((entry) => {
                const action = describeAction(entry.action);
                return (
                  <tr key={entry.id}>
                    <td className="mono">{formatTime(entry.created_at)}</td>
                    <td>
                      <span className="table__action">
                        <span className={'dot dot--' + action.tone} aria-hidden="true" />
                        <span>{action.label}</span>
                      </span>
                    </td>
                    <td>{entry.username ?? <span className="hint">nicht angemeldet / Befehlszeile</span>}</td>
                    <td className="table__target">{entry.target ?? ''}</td>
                  </tr>
                );
              })}
              {entries.length === 0 && !busy && (
                <tr>
                  <td colSpan={4} className="hint">
                    Keine Ereignisse gefunden.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        {next !== null && (
          <div className="row">
            <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => void load(applied, next, true)}>
              {busy ? 'Lädt …' : 'Ältere Ereignisse laden'}
            </button>
          </div>
        )}
      </section>
      <Unlock csrf={profile.csrf_token} />
    </>
  );
}
