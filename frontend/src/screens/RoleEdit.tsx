/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
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
import { t } from '../i18n';

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
  onBusyChange?: (busy: boolean) => void;
}

export function RoleEdit({ profile, id, onBusyChange }: Props) {
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
  useEffect(() => { onBusyChange?.(busy); }, [busy, onBusyChange]);

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
          setLoadError(t('Diese Rolle gibt es nicht (mehr).'));
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
        {t('Zurück zu den Rollen')}</a>
    </p>
  );

  if (!manage) {
    return (
      <div className="settings">
        {back}
        <Alert tone="warn">{t('Dir fehlt das Recht „Rollen und Rechte verwalten“. Die Rollen kannst du in der Rechte-Matrix ansehen.')}</Alert>
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
    return <div className="center">{t('Lädt …')}</div>;
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
    let text = t('Änderung: {what}. Gilt sofort für {n} Benutzer, auch in offenen Sitzungen.', { what: changeList() || t('nur Name oder Beschreibung'), n: role?.active_users ?? 0 });
    if (lockout.length > 0) {
      text += ' ' + t('{n} Benutzer verlieren den Zugang zur {area}verwaltung.', { n: role?.active_users ?? 0, area: lockout.includes('users.manage') ? t('Benutzer') : t('Rollen') });
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
      setNotice(t('Gespeichert. Die Änderung gilt sofort.'));
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
      {t('Benutzer mit dieser Rolle')}</a>
  );

  const saveButton = (() => {
    if (needsPassword) {
      return (
        <PasswordConfirm
          label={isNew ? t('Rolle anlegen') : t('Speichern')}
          tone="normal"
          confirmLabel={isNew ? t('Rolle anlegen') : t('Speichern')}
          question={isNew ? t('Die Rolle enthält gefährliche Rechte.') : question()}
          disabled={busy || !dirty || name === ''}
          onSubmit={(password) => save(password)}
        />
      );
    }
    if (permissionsChanged || lockout.length > 0) {
      return <Confirm label={t('Speichern')} confirmLabel={t('Speichern')} question={question()} busy={busy} disabled={!dirty} onConfirm={() => void save(null).catch(() => undefined)} />;
    }
    return (
      <button type="button" className="btn btn--solid" disabled={busy || !dirty || name === ''} onClick={() => void submit()}>
        {isNew ? t('Rolle anlegen') : t('Speichern')}
      </button>
    );
  })();

  const groups: { title: string; keys: PermissionDef[]; danger: boolean }[] = [
    { title: t('Rechte'), keys: catalog.filter((p) => !p.dangerous), danger: false },
    { title: t('Gefährlich – nur für alle Kategorien'), keys: catalog.filter((p) => p.dangerous), danger: true },
  ];

  return (
    <div className="settings">
      {back}
      {failure !== null && <Alert tone="err">{failure}</Alert>}
      {errors.delete !== undefined && filterLink !== null && (
        <Alert tone="warn">{t('Die Rolle ist noch zugewiesen. Entziehe sie zuerst den Benutzern:')} {filterLink}.</Alert>
      )}
      {notice !== null && <Alert tone="ok">{notice}</Alert>}
      <div className="form">
        <section className="card" aria-label={t('Stammdaten')}>
          <h2>{t('Stammdaten')}</h2>
          {role !== null && (
            <div className="badges">
              <span className={role.builtin ? 'badge' : 'badge badge--ok'}>{role.builtin ? 'Standardrolle' : t('Eigene Rolle')}</span>
            </div>
          )}
          {nameLocked ? (
            <p>
              <strong>{role.name}</strong>
              <br />
              <span className="hint">{role.description ?? t('Name und Beschreibung der Standardrollen sind fest.')}</span>
            </p>
          ) : (
            <>
              <FormField label={t('Name')} hint={t('2 bis 40 Zeichen: Buchstaben, Ziffern, Leerzeichen, Punkt, Unterstrich, Bindestrich.')} error={errors.name}>
                {(aria) => <input {...aria} className="input" name="role-name" value={name} maxLength={MAX_NAME} onChange={(event) => setName(event.target.value)} />}
              </FormField>
              <FormField label={t('Beschreibung (optional)')} hint={t('{n} von {max} Zeichen', { n: description.length, max: MAX_DESCRIPTION })} error={errors.description}>
                {(aria) => (
                  <input {...aria} className="input" name="role-description" value={description} maxLength={MAX_DESCRIPTION} onChange={(event) => setDescription(event.target.value)} />
                )}
              </FormField>
            </>
          )}
        </section>

        <section className="card" aria-label={t('Rechte')}>
          <h2>{t('Rechte')}</h2>
          {isAdmin && <Alert tone="info">{t('Die Rolle Admin hat immer alle Rechte.')}</Alert>}
          {!isAdmin && heldByMe && <Alert tone="info">{t('Diese Rolle ist dir selbst zugewiesen. Rechte ändert ein anderer Administrator.')}</Alert>}
          {!isAdmin && !heldByMe && role !== null && !role.permissions_editable && <Alert tone="info">{t('Die Rechte dieser Rolle kannst du nicht ändern.')}</Alert>}
          {errors.permissions !== undefined && <p className="field__error">{errors.permissions}</p>}
          {newDangerousOnRestricted && (
            <Alert tone="warn">
              {t('{n} Zuweisungen dieser Rolle gelten nur für ausgewählte Kategorien. Ein gefährliches Recht geht nur mit „alle Kategorien“:', { n: String(restricted) })} {filterLink}.
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
                      {permission.dangerous && <span className="badge badge--danger perm__badge">{t('gefährlich')}</span>}
                      <span className="hint perm__hint">
                        {t(PERMISSION_HINTS[permission.key] ?? '')}
                        {lacking && !permissionsLocked && ' ' + t('Du hast dieses Recht selbst nicht.')}
                      </span>
                    </span>
                  </label>
                );
              })}
            </fieldset>
          ))}
        </section>

        {role !== null && (
          <section className="card" aria-label={t('Auswirkung')}>
            <h2>{t('Auswirkung')}</h2>
            <p>
              {t('Gilt sofort für {n} Benutzer, auch in offenen Sitzungen. Zuweisungen: {a}.', { n: String(role.active_users), a: String(role.assignments) })}
              {role.assignments > 0 && <> {filterLink}.</>}
            </p>
          </section>
        )}

        <div className="row">
          {saveButton}
          {role !== null && (
            <button type="button" className="btn btn--ghost" onClick={duplicate}>
              {t('Duplizieren')}</button>
          )}
        </div>
      </div>

      {role !== null && !role.builtin && (
        <section className="card" aria-label={t('Rolle löschen')}>
          <h2>{t('Rolle löschen')}</h2>
          {role.deletable ? (
            <p className="hint">{t('Die Rolle ist niemandem zugewiesen und kann gelöscht werden.')}</p>
          ) : heldByMe ? (
            <p className="hint">{t('Diese Rolle ist dir selbst zugewiesen und lässt sich nicht löschen.')}</p>
          ) : (
            <p className="hint">{t('Die Rolle ist {n} Mal zugewiesen. Entziehe sie zuerst den Benutzern:', { n: String(role.assignments) })} {filterLink}.</p>
          )}
          <div className="row">
            <Confirm
              label={t('Rolle löschen')}
              question={t('Rolle „{name}“ endgültig löschen?', { name: role.name })}
              confirmLabel={t('Endgültig löschen')}
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
