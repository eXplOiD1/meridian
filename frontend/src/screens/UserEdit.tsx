import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Alert } from '../components/Alert';
import { AssignmentEditor, isDangerousRole, validAssignments } from '../components/AssignmentEditor';
import { Confirm } from '../components/Confirm';
import { FormField } from '../components/FormField';
import { OneTimePassword } from '../components/OneTimePassword';
import { PasswordConfirm } from '../components/PasswordConfirm';
import { request } from '../lib/api';
import { errorMessage, fieldErrors } from '../lib/errors';
import { formatTime } from '../lib/format';
import { hrefOf, navigate } from '../lib/useHashRoute';
import type { AssignmentInput, CategoryRef, OneTimePasswordReply, Profile, RoleCatalog, UserRow } from '../types';
import { STATUS_LABEL } from './Users';

interface Props {
  profile: Profile;
  /** null = neuer Benutzer */
  id: string | null;
}

interface Issued {
  username: string;
  password: string;
  expiresAt: string;
  /** Wohin nach „Fertig“ (nur beim Anlegen). */
  next: string | null;
}

function toInput(user: UserRow): AssignmentInput[] {
  return user.assignments.map((a) => ({ role_id: a.role_id, all_categories: a.all_categories, category_ids: a.categories.map((c) => c.id) }));
}

function same(a: AssignmentInput[], b: AssignmentInput[]): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

export function UserEdit({ profile, id }: Props) {
  const csrf = profile.csrf_token;
  const [user, setUser] = useState<UserRow | null>(null);
  const [catalog, setCatalog] = useState<RoleCatalog | null>(null);
  const [categories, setCategories] = useState<CategoryRef[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [username, setUsername] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [assignments, setAssignments] = useState<AssignmentInput[]>([]);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [info, setInfo] = useState<string | null>(null);
  const [issued, setIssued] = useState<Issued | null>(null);

  const isNew = id === null;
  const isSelf = user !== null && user.id === profile.user.id;

  const load = useCallback(async (): Promise<UserRow | null> => {
    try {
      const [roles, cats, detail] = await Promise.all([
        request<RoleCatalog>('GET', '/api/roles'),
        request<{ categories: CategoryRef[] }>('GET', '/api/categories?permission=jobs.view'),
        id === null ? Promise.resolve(null) : request<{ user: UserRow }>('GET', '/api/users/' + id),
      ]);
      setCatalog(roles);
      setCategories(cats.categories);
      if (detail !== null) {
        setUser(detail.user);
        setDisplayName(detail.user.display_name);
        setAssignments(toInput(detail.user));
        return detail.user;
      }
      return null;
    } catch (caught) {
      setLoadError(errorMessage(caught));
      return null;
    }
  }, [id]);

  useEffect(() => {
    void load();
  }, [load]);

  // Das Einmalpasswort verschwindet auch, wenn der Tab ausgeblendet oder die Seite verlassen wird (Unmount löscht den Zustand ohnehin).
  useEffect(() => {
    const clear = (): void => setIssued(null);
    window.addEventListener('pagehide', clear);
    return () => window.removeEventListener('pagehide', clear);
  }, []);

  if (loadError !== null) {
    return <Alert tone="err">{loadError}</Alert>;
  }
  if (catalog === null || (!isNew && user === null)) {
    return <p className="hint">Lädt …</p>;
  }

  // Passwort wird nötig, sobald eine gefährliche Rolle dazukommt oder wegfällt.
  const before = user === null ? [] : toInput(user);
  const dangerIds = (list: AssignmentInput[]): string =>
    list
      .filter((a) => isDangerousRole(catalog, a.role_id))
      .map((a) => a.role_id)
      .sort()
      .join(',');
  const needsPassword = dangerIds(assignments) !== dangerIds(before);
  const assignmentsValid = validAssignments(assignments);

  function fail(caught: unknown): void {
    setFields(fieldErrors(caught));
    setError(errorMessage(caught));
  }

  async function create(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setFields({});
    const typed = password;
    setPassword('');
    try {
      const body: Record<string, unknown> = { username: username.trim(), display_name: displayName, assignments };
      if (typed !== '') {
        body.current_password = typed;
      }
      const reply = await request<{ user: UserRow } & OneTimePasswordReply>('POST', '/api/users', { csrf, body });
      setIssued({ username: reply.user.username, password: reply.initial_password, expiresAt: reply.expires_at, next: 'benutzer/' + String(reply.user.id) });
    } catch (caught) {
      fail(caught);
    } finally {
      setBusy(false);
    }
  }

  async function saveName(event: FormEvent): Promise<void> {
    event.preventDefault();
    if (user === null) {
      return;
    }
    setBusy(true);
    setError(null);
    setFields({});
    setInfo(null);
    try {
      const reply = await request<{ user: UserRow }>('PUT', '/api/users/' + String(user.id), { csrf, body: { display_name: displayName } });
      setUser(reply.user);
      setDisplayName(reply.user.display_name);
      setInfo('Anzeigename gespeichert.');
    } catch (caught) {
      fail(caught);
    } finally {
      setBusy(false);
    }
  }

  async function saveAssignments(event: FormEvent): Promise<void> {
    event.preventDefault();
    if (user === null) {
      return;
    }
    setBusy(true);
    setError(null);
    setFields({});
    setInfo(null);
    const typed = password;
    setPassword('');
    try {
      const body: Record<string, unknown> = { assignments };
      if (typed !== '') {
        body.current_password = typed;
      }
      const reply = await request<{ user: UserRow }>('PUT', '/api/users/' + String(user.id) + '/assignments', { csrf, body });
      setUser(reply.user);
      setAssignments(toInput(reply.user));
      setInfo('Rollen und Kategorien gespeichert.');
    } catch (caught) {
      fail(caught);
    } finally {
      setBusy(false);
    }
  }

  /** Aktion ohne Passwort (aktivieren, deaktivieren, Sitzungen beenden, Sperre aufheben). */
  async function simple(action: () => Promise<string>): Promise<void> {
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

  if (issued !== null) {
    return (
      <div className="settings">
        <OneTimePassword
          username={issued.username}
          password={issued.password}
          expiresAt={issued.expiresAt}
          onDone={() => {
            const next = issued.next;
            setIssued(null);
            if (next !== null) {
              navigate(next);
            }
          }}
        />
      </div>
    );
  }

  const back = (
    <p className="crumbs">
      <a
        href={hrefOf('benutzer')}
        onClick={(event) => {
          event.preventDefault();
          navigate('benutzer');
        }}
      >
        ← Alle Benutzer
      </a>
    </p>
  );

  const passwordField = (
    <FormField label="Dein Passwort zur Bestätigung" hint="Nötig, weil eine Rolle mit gefährlichen Rechten dazukommt oder wegfällt." error={fields.current_password}>
      {(aria) => <input {...aria} className="input input--short" type="password" name="current_password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />}
    </FormField>
  );

  const messages = (
    <>
      {error !== null && (
        <Alert tone="err">
          {error}
          {Object.keys(fields).filter((k) => k.startsWith('assignments')).length > 0 && ' (Zuweisungen prüfen)'}
        </Alert>
      )}
      {info !== null && <Alert tone="ok">{info}</Alert>}
    </>
  );

  if (isNew) {
    return (
      <form className="settings editor" onSubmit={(event) => void create(event)}>
        {back}
        {messages}
        <section className="card" aria-label="Stammdaten">
          <h2>Neuer Benutzer</h2>
          <p className="card__lead">Meridian erzeugt ein Einmalpasswort, das nach dem Speichern genau einmal angezeigt wird. Beim ersten Anmelden legt der Benutzer ein eigenes Passwort fest.</p>
          <FormField label="Benutzername" hint="2 bis 64 Zeichen: Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich. Lässt sich später nicht ändern." error={fields.username}>
            {(aria) => <input {...aria} className="input input--short" name="username" autoComplete="off" required value={username} onChange={(e) => setUsername(e.target.value)} />}
          </FormField>
          <FormField label="Anzeigename" hint="1 bis 64 Zeichen, ohne Leerzeichen am Rand." error={fields.display_name}>
            {(aria) => <input {...aria} className="input" name="display_name" autoComplete="off" required value={displayName} onChange={(e) => setDisplayName(e.target.value)} />}
          </FormField>
        </section>
        <section className="card" aria-label="Rollen und Kategorien">
          <h2>Rollen und Kategorien</h2>
          <AssignmentEditor catalog={catalog} categories={categories} value={assignments} onChange={setAssignments} error={fields.assignments} />
          {needsPassword && passwordField}
        </section>
        <div className="row editor__actions">
          <button type="submit" className="btn btn--solid" disabled={busy || username.trim() === '' || displayName === '' || !assignmentsValid || (needsPassword && password === '')}>
            Benutzer anlegen
          </button>
          <a
            className="btn btn--ghost"
            href={hrefOf('benutzer')}
            onClick={(event) => {
              event.preventDefault();
              navigate('benutzer');
            }}
          >
            Abbrechen
          </a>
        </div>
      </form>
    );
  }

  if (user === null) {
    return null;
  }
  const uid = String(user.id);
  const deleted = user.status === 'deleted';
  const locked = isSelf || deleted;
  const selfHint = isSelf ? 'Für das eigene Konto: „Mein Konto“. Rollen ändert ein anderer Administrator.' : null;
  // Benutzer mit gefährlicher Rolle (Admin): Aktivieren und Deaktivieren verlangen das eigene Passwort (ADR 0005 E7).
  const targetDangerous = catalog !== null && user.assignments.some((a) => isDangerousRole(catalog, a.role_id));

  const issue = (path: string) => async (typed: string): Promise<void> => {
    const reply = await request<OneTimePasswordReply>('POST', '/api/users/' + uid + path, { csrf, body: { current_password: typed } });
    setInfo(null);
    setIssued({ username: user.username, password: reply.initial_password, expiresAt: reply.expires_at, next: null });
    await load();
  };

  return (
    <div className="settings editor">
      {back}
      {messages}
      <section className="card" aria-label="Stammdaten">
        <div className="row">
          <h2>{user.username}</h2>
          <span className={user.status === 'active' ? 'badge badge--ok' : 'badge badge--warn'}>{STATUS_LABEL[user.status]}</span>
          {user.password_change_required && <span className="badge badge--warn">Passwortwechsel offen</span>}
          {user.locked && <span className="badge badge--err">gesperrt</span>}
        </div>
        <form className="form" onSubmit={(event) => void saveName(event)}>
          <dl className="facts">
            <dt>Benutzername</dt>
            <dd>{user.username}</dd>
            <dt>Letzte Anmeldung</dt>
            <dd>{user.last_login_at === null ? 'nie' : formatTime(user.last_login_at)}</dd>
          </dl>
          <FormField label="Anzeigename" error={fields.display_name}>
            {(aria) => <input {...aria} className="input" name="display_name" autoComplete="off" required disabled={deleted} value={displayName} onChange={(e) => setDisplayName(e.target.value)} />}
          </FormField>
          <div className="row">
            <button type="submit" className="btn btn--solid" disabled={busy || deleted || displayName === user.display_name || displayName === ''}>
              Anzeigename speichern
            </button>
          </div>
        </form>
      </section>

      <form className="card" aria-label="Rollen und Kategorien" onSubmit={(event) => void saveAssignments(event)}>
        <h2>Rollen und Kategorien</h2>
        {selfHint !== null && <Alert tone="info">{selfHint}</Alert>}
        {user.assignments.some((a) => !a.effective) && <Alert tone="warn">Mindestens eine Zuweisung ist wirkungslos (ihre Kategorie wurde gelöscht). Sie gewährt nichts. Kategorien ergänzen oder die Zuweisung entfernen.</Alert>}
        <AssignmentEditor catalog={catalog} categories={categories} value={assignments} onChange={setAssignments} disabled={locked} error={fields.assignments} />
        {needsPassword && !locked && passwordField}
        <div className="row">
          <button type="submit" className="btn btn--solid" disabled={busy || locked || same(assignments, before) || !assignmentsValid || (needsPassword && password === '')}>
            Rollen speichern
          </button>
        </div>
      </form>

      <section className="card" aria-label="Sicherheit">
        <h2>Sicherheit</h2>
        {selfHint !== null && <p className="hint">{selfHint}</p>}
        <div className="actions">
          <div className="actions__item">
            <strong>Zwei-Faktor-Anmeldung: {user.totp_enabled ? 'aktiv' : 'aus'}</strong>
            <p className="hint">Zurücksetzen löscht den App-Schlüssel und die Wiederherstellungscodes. {user.username} richtet 2FA danach selbst neu ein.</p>
            <div>
              <PasswordConfirm
                label="2FA zurücksetzen"
                question={'Alle Sitzungen von ' + user.username + ' enden. ' + user.username + ' richtet 2FA neu ein.'}
                confirmLabel="2FA zurücksetzen"
                disabled={locked || !user.totp_enabled}
                accessibleName={'2FA von ' + user.username + ' zurücksetzen'}
                onSubmit={async (typed) => {
                  await request('POST', '/api/users/' + uid + '/2fa-reset', { csrf, body: { current_password: typed } });
                  setInfo('2FA von ' + user.username + ' ist zurückgesetzt, alle Sitzungen sind beendet.');
                  await load();
                }}
              />
            </div>
          </div>
          <div className="actions__item">
            <strong>Passwort</strong>
            <p className="hint">Erzeugt ein neues Einmalpasswort (7 Tage gültig), beendet alle Sitzungen und verlangt beim nächsten Anmelden ein eigenes Passwort. 2FA bleibt bestehen.</p>
            <div>
              <PasswordConfirm
                label="Passwort zurücksetzen"
                question={'Das bisherige Passwort von ' + user.username + ' wird ungültig, alle Sitzungen enden.'}
                confirmLabel="Einmalpasswort erzeugen"
                disabled={locked}
                accessibleName={'Passwort von ' + user.username + ' zurücksetzen'}
                onSubmit={issue('/password-reset')}
              />
            </div>
          </div>
          <div className="actions__item">
            <strong>Sitzungen: {user.session_count}</strong>
            <div>
              <Confirm
                label="Alle Sitzungen beenden"
                question={'Alle Sitzungen von ' + user.username + ' enden.'}
                confirmLabel="Sitzungen beenden"
                busy={busy}
                disabled={locked || user.session_count === 0}
                accessibleName={'Sitzungen von ' + user.username + ' beenden'}
                onConfirm={() =>
                  void simple(async () => {
                    await request('POST', '/api/users/' + uid + '/sessions/end', { csrf });
                    return 'Die Sitzungen von ' + user.username + ' sind beendet.';
                  })
                }
              />
            </div>
          </div>
          <div className="actions__item">
            <strong>Anmeldesperre{user.locked ? ': gesperrt' : ''}</strong>
            <p className="hint">Nach mehreren Fehlversuchen sperrt Meridian den Benutzernamen zeitweise.</p>
            <div>
              <button
                type="button"
                className="btn btn--ghost"
                disabled={busy || deleted || !user.locked}
                onClick={() =>
                  void simple(async () => {
                    await request('POST', '/api/users/unlock', { csrf, body: { username: user.username } });
                    return 'Die Sperre für ' + user.username + ' ist aufgehoben.';
                  })
                }
              >
                Sperre aufheben
              </button>
            </div>
          </div>
        </div>
      </section>

      <section className="card" aria-label="Konto">
        <h2>Konto</h2>
        {selfHint !== null && <p className="hint">{selfHint}</p>}
        {deleted && <p className="hint">Gelöschte Benutzer lassen sich nicht wiederherstellen. Für dieselbe Person einen neuen Benutzer anlegen.</p>}
        <div className="actions">
          <div className="actions__item">
            <strong>{user.status === 'inactive' ? 'Aktivieren' : 'Deaktivieren'}</strong>
            <p className="hint">
              {user.status === 'inactive'
                ? 'Der Benutzer kann sich danach wieder anmelden; seine Zuweisungen gelten wieder.'
                : 'Beendet alle Sitzungen, der Benutzer kann sich nicht mehr anmelden. Zuweisungen bleiben erhalten. Umkehrbar.'}
            </p>
            <div>
              {targetDangerous ? (
                <PasswordConfirm
                  label={user.status === 'inactive' ? 'Aktivieren' : 'Deaktivieren'}
                  question={
                    user.status === 'inactive'
                      ? user.username + ' hat Administratorrechte und kann sich danach wieder anmelden.'
                      : user.username + ' hat Administratorrechte, kann sich danach nicht mehr anmelden, alle Sitzungen enden.'
                  }
                  confirmLabel={user.status === 'inactive' ? 'Aktivieren' : 'Deaktivieren'}
                  tone={user.status === 'inactive' ? 'normal' : 'danger'}
                  disabled={locked}
                  accessibleName={user.username + (user.status === 'inactive' ? ' aktivieren' : ' deaktivieren')}
                  onSubmit={async (typed) => {
                    const action = user.status === 'inactive' ? 'activate' : 'deactivate';
                    await request('POST', '/api/users/' + uid + '/' + action, { csrf, body: { current_password: typed } });
                    setInfo(user.username + (action === 'activate' ? ' ist aktiviert.' : ' ist deaktiviert.'));
                    await load();
                  }}
                />
              ) : user.status === 'inactive' ? (
                <button
                  type="button"
                  className="btn btn--ghost"
                  disabled={busy || isSelf}
                  onClick={() =>
                    void simple(async () => {
                      await request('POST', '/api/users/' + uid + '/activate', { csrf });
                      return user.username + ' ist aktiviert.';
                    })
                  }
                >
                  Aktivieren
                </button>
              ) : (
                <Confirm
                  label="Deaktivieren"
                  question={user.username + ' kann sich nicht mehr anmelden, alle Sitzungen enden.'}
                  confirmLabel="Deaktivieren"
                  busy={busy}
                  disabled={locked}
                  accessibleName={user.username + ' deaktivieren'}
                  onConfirm={() =>
                    void simple(async () => {
                      await request('POST', '/api/users/' + uid + '/deactivate', { csrf });
                      return user.username + ' ist deaktiviert.';
                    })
                  }
                />
              )}
            </div>
          </div>
          <div className="actions__item">
            <strong>Löschen</strong>
            <p className="hint">
              Der Benutzer wird als gelöscht markiert: Anmeldung, Sitzungen, 2FA und Zuweisungen entfallen. Name und Anzeigename bleiben im Audit-Log und im Verlauf erhalten; der Benutzername bleibt belegt. Nicht umkehrbar.
            </p>
            <div>
              <PasswordConfirm
                label="Benutzer löschen"
                question={user.username + ' wird gelöscht und kann sich nie wieder anmelden.'}
                confirmLabel="Endgültig löschen"
                disabled={locked}
                accessibleName={user.username + ' löschen'}
                onSubmit={async (typed) => {
                  await request('DELETE', '/api/users/' + uid, { csrf, body: { current_password: typed } });
                  navigate('benutzer');
                }}
              />
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
