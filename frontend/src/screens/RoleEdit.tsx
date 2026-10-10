import { useEffect, useState } from 'react';
import { Alert } from '../components/Alert';
import { Confirm } from '../components/Confirm';
import { FormField } from '../components/FormField';
import { PasswordConfirm } from '../components/PasswordConfirm';
import { request } from '../lib/api';
import { errorMessage, fieldErrors, isStatus } from '../lib/errors';
import { canManageRoles, holdsUnrestricted, permissionLabel, PERMISSION_HINTS } from '../lib/permissions';
import { hrefOf, navigate } from '../lib/useHashRoute';
import type { ManagedRole, ManagedRoles, PermissionDef, Profile } from '../types';

const MAX_NAME = 40;
const MAX_DESCRIPTION = 200;

/** „Duplizieren“: Rechte und Beschreibung nur im Arbeitsspeicher an den Anlegen-Bildschirm übergeben. */
let duplicateDraft: { name: string; description: string; permissions: string[] } | null = null;

function takeDuplicate(): { name: string; description: string; permissions: string[] } | null {
  const draft = duplicateDraft;
  duplicateDraft = null;
  return draft;
}

interface Props {
  profile: Profile;
  id: string | null;
}

export function RoleEdit({ profile, id }: Props) {
  const manage = canManageRoles(profile);
  const csrf = profile.csrf_token;
  const [data, setData] = useState<ManagedRoles | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [role, setRole] = useState<ManagedRole | null>(null);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [permissions, setPermissions] = useState<string[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function adopt(next: ManagedRole): void {
    setRole(next);
    setName(next.name);
    setDescription(next.description ?? '');
    setPermissions(next.permissions);
  }

  useEffect(() => {
    if (!manage) {
      return undefined;
    }
    let alive = true;
    request<ManagedRoles>('GET', '/api/roles/manage')
      .then((reply) => {
        if (!alive) {
          return;
        }
        setData(reply);
        if (id === null) {
          const draft = takeDuplicate();
          if (draft !== null) {
            setName(draft.name);
            setDescription(draft.description);
            setPermissions(draft.permissions);
          }
          return;
        }
        const found = reply.roles.find((r) => String(r.id) === id);
        if (found === undefined) {
          setLoadError('Diese Rolle gibt es nicht (mehr).');
        } else {
          adopt(found);
        }
      })
      .catch((caught: unknown) => {
        if (alive) {
          setLoadError(errorMessage(caught));
        }
      });
    return () => {
      alive = false;
    };
  }, [id, manage]);

  const back = (
    <p>
      <a
        href={hrefOf('benutzer/rollen')}
        onClick={(event) => {
          event.preventDefault();
          navigate('benutzer/rollen');
        }}
      >
        Zurück zu den Rollen
      </a>
    </p>
  );

  if (!manage) {
    return (
      <div className="settings">
        {back}
        <Alert tone="warn">Dir fehlt das Recht „Rollen und Rechte verwalten“. Die Rollen kannst du in der Rechte-Matrix ansehen.</Alert>
      </div>
    );
  }
  if (loadError !== null) {
    return (
      <div className="settings">
        {back}
        <Alert tone="err">{loadError}</Alert>
      </div>
    );
  }
  if (data === null || (id !== null && role === null)) {
    return <div className="center">Lädt …</div>;
  }

  const catalog: PermissionDef[] = data.permissions;
  const isNew = id === null;
  const isAdmin = role?.builtin_key === 'admin';
  const nameLocked = role !== null && role.builtin;
  const heldByMe = role?.held_by_me === true;
  const permissionsLocked = isAdmin || (role !== null && !role.permissions_editable);
  const shown = isAdmin ? catalog.map((p) => p.key) : permissions;

  const original = role?.permissions ?? [];
  const added = permissions.filter((key) => !original.includes(key));
  const removed = original.filter((key) => !permissions.includes(key));
  const permissionsChanged = !isNew && !permissionsLocked && (added.length > 0 || removed.length > 0);
  const dangerousKeys = new Set(catalog.filter((p) => p.dangerous).map((p) => p.key));
  const needsPassword = [...(isNew ? permissions : [...added, ...removed])].some((key) => dangerousKeys.has(key));
  const metaChanged = role !== null && !nameLocked && (name !== role.name || (description === '' ? null : description) !== role.description);
  const dirty = isNew ? name !== '' : metaChanged || permissionsChanged;
  const lockout = [...removed].filter((key) => key === 'users.manage' || key === 'roles.manage');
  const restricted = role?.restricted_assignments ?? 0;
  const newDangerousOnRestricted = restricted > 0 && added.some((key) => dangerousKeys.has(key));

  function toggle(key: string, on: boolean): void {
    setPermissions((current) => (on ? [...current.filter((k) => k !== key), key] : current.filter((k) => k !== key)));
  }

  function changeList(): string {
    const parts = [...added.map((k) => '+ ' + permissionLabel(k)), ...removed.map((k) => '− ' + permissionLabel(k))];
    return parts.join(', ');
  }

  function question(): string {
    let text = 'Änderung: ' + (changeList() || 'nur Name oder Beschreibung') + '. Gilt sofort für ' + String(role?.active_users ?? 0) + ' Benutzer, auch in offenen Sitzungen.';
    if (lockout.length > 0) {
      text += ' ' + String(role?.active_users ?? 0) + ' Benutzer verlieren den Zugang zur ' + (lockout.includes('users.manage') ? 'Benutzer' : 'Rollen') + 'verwaltung.';
    }
    return text;
  }

  function fail(caught: unknown): void {
    const fields = fieldErrors(caught);
    setErrors(fields);
    if (fields.current_password !== undefined || (isStatus(caught, 403) && /passwort/i.test(errorMessage(caught)))) {
      return;
    }
    if (Object.keys(fields).length === 0 || isStatus(caught, 409) || isStatus(caught, 403)) {
      setFailure(errorMessage(caught));
    } else if (fields.body !== undefined || fields.permissions !== undefined) {
      setFailure(Object.values(fields).join(' '));
    }
  }

  /** Genau eine Passwort-Bestätigung je Speichern: sie geht nur an die Anfrage, die Rechte schreibt. */
  async function save(password: string | null): Promise<void> {
    setBusy(true);
    setErrors({});
    setFailure(null);
    setNotice(null);
    try {
      const trimmed = description === '' ? null : description;
      if (isNew) {
        const body: Record<string, unknown> = { name, permissions };
        if (trimmed !== null) {
          body.description = trimmed;
        }
        if (password !== null) {
          body.current_password = password;
        }
        const reply = await request<{ role: ManagedRole }>('POST', '/api/roles', { csrf, body });
        navigate('benutzer/rollen/' + String(reply.role.id));
        return;
      }
      let current = role as ManagedRole;
      if (metaChanged) {
        current = (await request<{ role: ManagedRole }>('PUT', '/api/roles/' + String(current.id), { csrf, body: { name, description: trimmed } })).role;
        setRole(current);
      }
      if (permissionsChanged) {
        const body: Record<string, unknown> = { permissions };
        if (password !== null) {
          body.current_password = password;
        }
        current = (await request<{ role: ManagedRole }>('PUT', '/api/roles/' + String(current.id) + '/permissions', { csrf, body })).role;
        adopt(current);
      }
      setNotice('Gespeichert. Die Änderung gilt sofort.');
    } catch (caught) {
      fail(caught);
      throw caught;
    } finally {
      setBusy(false);
    }
  }

  async function submit(): Promise<void> {
    try {
      await save(null);
    } catch {
      // Meldung steht bereits am Feld oder im Alert.
    }
  }

  async function remove(): Promise<void> {
    if (role === null) {
      return;
    }
    setBusy(true);
    setFailure(null);
    try {
      await request('DELETE', '/api/roles/' + String(role.id), { csrf });
      navigate('benutzer/rollen');
    } catch (caught) {
      setFailure(errorMessage(caught));
      setErrors(isStatus(caught, 409) ? { delete: 'assignments' } : {});
    } finally {
      setBusy(false);
    }
  }

  function duplicate(): void {
    if (role === null) {
      return;
    }
    duplicateDraft = { name: '', description: role.description ?? '', permissions: role.permissions.filter((key) => holdsUnrestricted(profile, key)) };
    navigate('benutzer/rollen/neu');
  }

  const filterLink = role === null ? null : (
    <a
      href={hrefOf('benutzer?rolle=' + String(role.id))}
      onClick={(event) => {
        event.preventDefault();
        navigate('benutzer?rolle=' + String(role.id));
      }}
    >
      Benutzer mit dieser Rolle
    </a>
  );

  const saveButton = (() => {
    if (needsPassword) {
      return (
        <PasswordConfirm
          label={isNew ? 'Rolle anlegen' : 'Speichern'}
          tone="normal"
          confirmLabel={isNew ? 'Rolle anlegen' : 'Speichern'}
          question={isNew ? 'Die Rolle enthält gefährliche Rechte.' : question()}
          disabled={busy || !dirty || name === ''}
          onSubmit={(password) => save(password)}
        />
      );
    }
    if (permissionsChanged || lockout.length > 0) {
      return <Confirm label="Speichern" confirmLabel="Speichern" question={question()} busy={busy} disabled={!dirty} onConfirm={() => void save(null).catch(() => undefined)} />;
    }
    return (
      <button type="button" className="btn btn--solid" disabled={busy || !dirty || name === ''} onClick={() => void submit()}>
        {isNew ? 'Rolle anlegen' : 'Speichern'}
      </button>
    );
  })();

  const groups: { title: string; keys: PermissionDef[]; danger: boolean }[] = [
    { title: 'Rechte', keys: catalog.filter((p) => !p.dangerous), danger: false },
    { title: 'Gefährlich – nur für alle Kategorien', keys: catalog.filter((p) => p.dangerous), danger: true },
  ];

  return (
    <div className="settings">
      {back}
      {failure !== null && <Alert tone="err">{failure}</Alert>}
      {errors.delete !== undefined && filterLink !== null && (
        <Alert tone="warn">Die Rolle ist noch zugewiesen. Entziehe sie zuerst den Benutzern: {filterLink}.</Alert>
      )}
      {notice !== null && <Alert tone="ok">{notice}</Alert>}
      <div className="form">
        <section className="card" aria-label="Stammdaten">
          <h2>Stammdaten</h2>
          {role !== null && (
            <div className="badges">
              <span className={role.builtin ? 'badge' : 'badge badge--ok'}>{role.builtin ? 'Standardrolle' : 'Eigene Rolle'}</span>
            </div>
          )}
          {nameLocked ? (
            <p>
              <strong>{role.name}</strong>
              <br />
              <span className="hint">{role.description ?? 'Name und Beschreibung der Standardrollen sind fest.'}</span>
            </p>
          ) : (
            <>
              <FormField label="Name" hint="2 bis 40 Zeichen: Buchstaben, Ziffern, Leerzeichen, Punkt, Unterstrich, Bindestrich." error={errors.name}>
                {(aria) => <input {...aria} className="input" name="role-name" value={name} maxLength={MAX_NAME} onChange={(event) => setName(event.target.value)} />}
              </FormField>
              <FormField label="Beschreibung (optional)" hint={String(description.length) + ' von ' + String(MAX_DESCRIPTION) + ' Zeichen'} error={errors.description}>
                {(aria) => (
                  <input {...aria} className="input" name="role-description" value={description} maxLength={MAX_DESCRIPTION} onChange={(event) => setDescription(event.target.value)} />
                )}
              </FormField>
            </>
          )}
        </section>

        <section className="card" aria-label="Rechte">
          <h2>Rechte</h2>
          {isAdmin && <Alert tone="info">Die Rolle Admin hat immer alle Rechte.</Alert>}
          {!isAdmin && heldByMe && <Alert tone="info">Diese Rolle ist dir selbst zugewiesen. Rechte ändert ein anderer Administrator.</Alert>}
          {!isAdmin && !heldByMe && role !== null && !role.permissions_editable && <Alert tone="info">Die Rechte dieser Rolle kannst du nicht ändern.</Alert>}
          {errors.permissions !== undefined && <p className="field__error">{errors.permissions}</p>}
          {newDangerousOnRestricted && (
            <Alert tone="warn">
              {String(restricted)} Zuweisungen dieser Rolle gelten nur für ausgewählte Kategorien. Ein gefährliches Recht geht nur mit „alle Kategorien“: {filterLink}.
            </Alert>
          )}
          {groups.map((group) => (
            <fieldset key={group.title} className="perm-group">
              <legend className={group.danger ? 'perm-group__legend perm-group__legend--danger' : 'perm-group__legend'}>{group.title}</legend>
              {group.keys.map((permission) => {
                const checked = shown.includes(permission.key);
                const lacking = !holdsUnrestricted(profile, permission.key);
                const disabled = busy || permissionsLocked || lacking;
                return (
                  <label className="check perm" key={permission.key}>
                    <input type="checkbox" checked={checked} disabled={disabled} onChange={(event) => toggle(permission.key, event.target.checked)} />
                    <span>
                      <strong>{permissionLabel(permission.key)}</strong>
                      {permission.dangerous && <span className="badge badge--danger perm__badge">gefährlich</span>}
                      <span className="hint perm__hint">
                        {PERMISSION_HINTS[permission.key] ?? ''}
                        {lacking && !permissionsLocked && ' Du hast dieses Recht selbst nicht.'}
                      </span>
                    </span>
                  </label>
                );
              })}
            </fieldset>
          ))}
        </section>

        {role !== null && (
          <section className="card" aria-label="Auswirkung">
            <h2>Auswirkung</h2>
            <p>
              Gilt sofort für {String(role.active_users)} Benutzer, auch in offenen Sitzungen. Zuweisungen: {String(role.assignments)}.
              {role.assignments > 0 && <> {filterLink}.</>}
            </p>
          </section>
        )}

        <div className="row">
          {saveButton}
          {role !== null && (
            <button type="button" className="btn btn--ghost" onClick={duplicate}>
              Duplizieren
            </button>
          )}
        </div>
      </div>

      {role !== null && !role.builtin && (
        <section className="card" aria-label="Rolle löschen">
          <h2>Rolle löschen</h2>
          {role.deletable ? (
            <p className="hint">Die Rolle ist niemandem zugewiesen und kann gelöscht werden.</p>
          ) : heldByMe ? (
            <p className="hint">Diese Rolle ist dir selbst zugewiesen und lässt sich nicht löschen.</p>
          ) : (
            <p className="hint">Die Rolle ist {String(role.assignments)} Mal zugewiesen. Entziehe sie zuerst den Benutzern: {filterLink}.</p>
          )}
          <div className="row">
            <Confirm
              label="Rolle löschen"
              question={'Rolle „' + role.name + '“ endgültig löschen?'}
              confirmLabel="Endgültig löschen"
              busy={busy}
              disabled={!role.deletable}
              onConfirm={() => void remove()}
            />
          </div>
        </section>
      )}
    </div>
  );
}
