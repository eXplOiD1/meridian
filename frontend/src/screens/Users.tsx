/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { useEffect, useState } from 'react';
import { Alert } from '../components/Alert';
import { request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatTime } from '../lib/format';
import { canManageRoles, canManageUsers, permissionLabel } from '../lib/permissions';
import { hrefOf, navigate } from '../lib/useHashRoute';
import type { ManagedRoles, Profile, RoleCatalog, RoleDef, UserRow, UserStatus } from '../types';
import { t } from '../i18n';

type Filter = UserStatus | 'all';

const FILTERS: { value: Filter; label: string }[] = [
  { value: 'active', label: 'Aktiv' },
  { value: 'inactive', label: 'Deaktiviert' },
  { value: 'deleted', label: 'Gelöscht' },
  { value: 'all', label: 'Alle' },
];

export const STATUS_LABEL: Record<UserStatus, string> = { active: 'aktiv', inactive: 'deaktiviert', deleted: 'gelöscht' };

function Tabs({ current, profile }: { current: 'users' | 'roles'; profile: Profile }) {
  return (
    <nav className="tabs" aria-label={t('Benutzer und Rollen')}>
      {canManageUsers(profile) && (
      <a
        href={hrefOf('benutzer')}
        aria-current={current === 'users' ? 'page' : undefined}
        onClick={(event) => {
          event.preventDefault();
          navigate('benutzer');
        }}
      >
        {t('Benutzer')}</a>
      )}
      <a
        href={hrefOf('benutzer/rollen')}
        aria-current={current === 'roles' ? 'page' : undefined}
        onClick={(event) => {
          event.preventDefault();
          navigate('benutzer/rollen');
        }}
      >
        {t('Rollen und Rechte')}</a>
    </nav>
  );
}

function assignmentText(user: UserRow): string {
  if (user.assignments.length === 0) {
    return t('keine Rolle');
  }
  return user.assignments
    .map((a) => a.role + (a.all_categories ? ' (' + t('alle Kategorien') + ')' : ' (' + (a.categories.length === 0 ? t('keine Kategorie') : a.categories.map((c) => c.name).join(', ')) + ')'))
    .join('; ');
}

export function Users({ profile, roleId }: { profile: Profile; roleId: string | null }) {
  const [filter, setFilter] = useState<Filter>('active');
  const [users, setUsers] = useState<UserRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [roles, setRoles] = useState<RoleDef[]>([]);

  useEffect(() => {
    let alive = true;
    request<RoleCatalog>('GET', '/api/roles')
      .then((reply) => {
        if (alive) {
          setRoles(reply.roles);
        }
      })
      .catch(() => undefined);
    return () => {
      alive = false;
    };
  }, []);

  useEffect(() => {
    let alive = true;
    setUsers(null);
    setError(null);
    const query = [filter === 'all' ? null : 'status=' + filter, roleId === null ? null : 'role_id=' + roleId].filter((x): x is string => x !== null);
    request<{ users: UserRow[] }>('GET', '/api/users' + (query.length === 0 ? '' : '?' + query.join('&')))
      .then((reply) => {
        if (alive) {
          setUsers(reply.users);
        }
      })
      .catch((caught: unknown) => {
        if (alive) {
          setError(errorMessage(caught));
        }
      });
    return () => {
      alive = false;
    };
  }, [filter, roleId]);

  return (
    <div className="settings">
      <Tabs current="users" profile={profile} />
      <section className="card" aria-label={t('Benutzer')}>
        <div className="row">
          <h2>{t('Benutzer')}</h2>
          <a
            className="btn btn--solid"
            href={hrefOf('benutzer/neu')}
            onClick={(event) => {
              event.preventDefault();
              navigate('benutzer/neu');
            }}
          >
            {t('Benutzer anlegen')}</a>
        </div>
        <div className="row">
          <label className="field__label" htmlFor="user-status">
            {t('Status')}</label>
          <select id="user-status" className="select" value={filter} onChange={(event) => setFilter(event.target.value as Filter)}>
            {FILTERS.map((entry) => (
              <option key={entry.value} value={entry.value}>
                {t(entry.label)}
              </option>
            ))}
          </select>
          <label className="field__label" htmlFor="user-role">
            {t('Rolle')}</label>
          <select
            id="user-role"
            className="select"
            value={roleId ?? ''}
            onChange={(event) => navigate(event.target.value === '' ? 'benutzer' : 'benutzer?rolle=' + event.target.value)}
          >
            <option value="">{t('Alle Rollen')}</option>
            {roles.map((role) => (
              <option key={role.id} value={role.id}>
                {role.name}
              </option>
            ))}
          </select>
        </div>
        {error !== null && <Alert tone="err">{error}</Alert>}
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th scope="col">{t('Benutzer')}</th>
                <th scope="col">{t('Anzeigename')}</th>
                <th scope="col">{t('Rollen')}</th>
                <th scope="col">{t('2FA')}</th>
                <th scope="col">{t('Status')}</th>
                <th scope="col">{t('Letzte Anmeldung')}</th>
              </tr>
            </thead>
            <tbody>
              {(users ?? []).map((user) => (
                <tr key={user.id}>
                  <td>
                    <a
                      href={hrefOf('benutzer/' + String(user.id))}
                      onClick={(event) => {
                        event.preventDefault();
                        navigate('benutzer/' + String(user.id));
                      }}
                    >
                      <strong>{user.username}</strong>
                    </a>
                  </td>
                  <td>{user.display_name}</td>
                  <td>
                    {assignmentText(user)}
                    {user.assignments.some((a) => !a.effective) && (
                      <div className="badges">
                        <span className="badge badge--warn">{t('wirkungslose Zuweisung')}</span>
                      </div>
                    )}
                  </td>
                  <td>{user.totp_enabled ? t('aktiv') : <span className="hint">{t('aus')}</span>}</td>
                  <td>
                    {t(STATUS_LABEL[user.status])}
                    {(user.password_change_required || user.locked) && (
                      <div className="badges">
                        {user.password_change_required && <span className="badge badge--warn">{t('Passwortwechsel offen')}</span>}
                        {user.locked && <span className="badge badge--err">{t('gesperrt')}</span>}
                      </div>
                    )}
                  </td>
                  <td className="mono">{user.last_login_at === null ? 'nie' : formatTime(user.last_login_at)}</td>
                </tr>
              ))}
              {users !== null && users.length === 0 && (
                <tr>
                  <td colSpan={6} className="hint">
                    {t('Keine Benutzer mit diesem Status und dieser Rolle.')}</td>
                </tr>
              )}
              {users === null && error === null && (
                <tr>
                  <td colSpan={6} className="hint">
                    {t('Lädt …')}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

export function Roles({ profile }: { profile: Profile }) {
  const manage = canManageRoles(profile);
  const [managed, setManaged] = useState<ManagedRoles | null>(null);
  const [catalog, setCatalog] = useState<RoleCatalog | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    const load = manage
      ? request<ManagedRoles>('GET', '/api/roles/manage').then((reply) => {
          if (alive) {
            setManaged(reply);
            setCatalog(reply);
          }
        })
      : request<RoleCatalog>('GET', '/api/roles').then((reply) => {
          if (alive) {
            setCatalog(reply);
          }
        });
    load.catch((caught: unknown) => {
      if (alive) {
        setError(errorMessage(caught));
      }
    });
    return () => {
      alive = false;
    };
  }, [manage]);

  const dangerousKeys = new Set((catalog?.permissions ?? []).filter((p) => p.dangerous).map((p) => p.key));
  const dangerousCount = (role: RoleDef): number => role.permissions.filter((key) => dangerousKeys.has(key)).length;

  return (
    <div className="settings">
      <Tabs current="roles" profile={profile} />
      <section className="card" aria-label={t('Rollen')}>
        <div className="row">
          <h2>{t('Rollen')}</h2>
          {manage && (
            <a
              className="btn btn--solid"
              href={hrefOf('benutzer/rollen/neu')}
              onClick={(event) => {
                event.preventDefault();
                navigate('benutzer/rollen/neu');
              }}
            >
              {t('Rolle anlegen')}</a>
          )}
        </div>
        <p className="card__lead">
          {t('Eine Rolle ist eine Zusammenstellung von Rechten. Einem Benutzer lassen sich mehrere Rollen zuweisen, jeweils für alle oder für ausgewählte Kategorien. Gefährliche Rechte gelten nur für „alle Kategorien“.')}
          {!manage && ' ' + t('Du kannst die Rollen ansehen, aber nicht ändern.')}
        </p>
        {error !== null && <Alert tone="err">{error}</Alert>}
        {catalog !== null && (
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th scope="col">{t('Rolle')}</th>
                  <th scope="col">{t('Art')}</th>
                  <th scope="col">{t('Rechte')}</th>
                  {managed !== null && <th scope="col">{t('Zuweisungen')}</th>}
                  {managed !== null && <th scope="col">{t('Aktive Benutzer')}</th>}
                </tr>
              </thead>
              <tbody>
                {(managed?.roles ?? catalog.roles).map((role) => {
                  const rows = managed?.roles.find((m) => m.id === role.id);
                  const danger = dangerousCount(role);
                  return (
                    <tr key={role.id}>
                      <td>
                        {manage ? (
                          <a
                            href={hrefOf('benutzer/rollen/' + String(role.id))}
                            onClick={(event) => {
                              event.preventDefault();
                              navigate('benutzer/rollen/' + String(role.id));
                            }}
                          >
                            <strong>{role.name}</strong>
                          </a>
                        ) : (
                          <strong>{role.name}</strong>
                        )}
                        {role.description !== null && <div className="hint">{role.description}</div>}
                      </td>
                      <td>
                        <span className={role.builtin ? 'badge' : 'badge badge--ok'}>{role.builtin ? t('Standard') : 'Eigene'}</span>
                      </td>
                      <td>
                        {String(role.permissions.length)}
                        {danger > 0 && (
                          <div className="badges">
                            <span className="badge badge--danger">{String(danger)} {t('gefährlich')}</span>
                          </div>
                        )}
                      </td>
                      {rows !== undefined && <td>{String(rows.assignments)}</td>}
                      {rows !== undefined && <td>{String(rows.active_users)}</td>}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>
      <section className="card" aria-label={t('Rechte-Matrix')}>
        <h2>{t('Rechte-Matrix')}</h2>
        {catalog !== null && (
          <div className="table-wrap">
            <table className="table matrix">
              <thead>
                <tr>
                  <th scope="col">{t('Recht')}</th>
                  {catalog.roles.map((role) => (
                    <th scope="col" key={role.id}>
                      {role.name}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {catalog.permissions.map((permission) => (
                  <tr key={permission.key}>
                    <th scope="row">
                      {permissionLabel(permission.key)}
                      <div className="badges">
                        {permission.dangerous && <span className="badge badge--danger">{t('gefährlich, nur für alle Kategorien')}</span>}
                        {!permission.scoped && !permission.dangerous && <span className="badge">{t('ohne Kategoriebezug')}</span>}
                      </div>
                    </th>
                    {catalog.roles.map((role) => {
                      const has = role.permissions.includes(permission.key);
                      return (
                        <td key={role.id} className={has ? 'yes' : 'no'}>
                          <span aria-hidden="true">{has ? '✓' : '–'}</span>
                          <span className="sr-only">{has ? 'ja' : 'nein'}</span>
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
