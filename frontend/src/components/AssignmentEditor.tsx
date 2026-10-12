/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { useId } from 'react';
import type { AssignmentInput, CategoryRef, RoleCatalog, RoleDef } from '../types';
import { Alert } from './Alert';
import { t } from '../i18n';

interface Props {
  catalog: RoleCatalog;
  categories: CategoryRef[];
  value: AssignmentInput[];
  onChange: (next: AssignmentInput[]) => void;
  disabled?: boolean;
  /** Feldfehler des Servers (Schlüssel „assignments“ oder „assignments.0.…“). */
  error?: string | undefined;
}

export function roleOf(catalog: RoleCatalog, id: number): RoleDef | undefined {
  return catalog.roles.find((role) => role.id === id);
}

/** Rolle mit mindestens einem gefährlichen Recht: gilt nur für alle Kategorien und braucht das Passwort des Handelnden. */
export function isDangerousRole(catalog: RoleCatalog, id: number): boolean {
  const role = roleOf(catalog, id);
  if (role === undefined) {
    return false;
  }
  return role.permissions.some((key) => catalog.permissions.some((p) => p.key === key && p.dangerous));
}

/** „Alle Kategorien“ und eine Auswahl schließen sich aus; eine leere Auswahl heißt nie „alle“. */
export function AssignmentEditor({ catalog, categories, value, onChange, disabled = false, error }: Props) {
  const base = useId();
  const free = catalog.roles.filter((role) => !value.some((entry) => entry.role_id === role.id));

  function update(index: number, patch: Partial<AssignmentInput>): void {
    onChange(value.map((entry, i) => (i === index ? { ...entry, ...patch } : entry)));
  }

  return (
    <div className="form">
      {value.length === 0 && <p className="hint">{t('Keine Rolle zugewiesen: Der Benutzer kann sich anmelden, sieht aber nur „Mein Konto“.')}</p>}
      {value.map((entry, index) => {
        const role = roleOf(catalog, entry.role_id);
        const dangerous = isDangerousRole(catalog, entry.role_id);
        const key = base + '-' + String(entry.role_id);
        const ineffective = !entry.all_categories && entry.category_ids.length === 0;
        return (
          <div className="assign" key={entry.role_id} role="group" aria-label={t('Zuweisung {name}', { name: role?.name ?? t('Rolle') })}>
            <div className="assign__head">
              <strong>{role?.name ?? t('Unbekannte Rolle')}</strong>
              <button type="button" className="btn btn--ghost btn--small" disabled={disabled} onClick={() => onChange(value.filter((_, i) => i !== index))}>
                {t('Zuweisung entfernen')}</button>
            </div>
            {role?.description != null && <p className="hint">{role.description}</p>}
            {dangerous && (
              <Alert tone="warn">
                {t('Diese Rolle enthält gefährliche Rechte. Sie gilt nur für alle Kategorien. Das Ändern verlangt dein Passwort.')}</Alert>
            )}
            <label className="check">
              <input
                type="checkbox"
                checked={entry.all_categories}
                disabled={disabled || dangerous}
                onChange={(event) => update(index, { all_categories: event.target.checked, category_ids: [] })}
              />
              <span>{t('Alle Kategorien')}{dangerous ? ' ' + t('(für diese Rolle vorgeschrieben)') : ''}</span>
            </label>
            {!entry.all_categories && (
              <fieldset className="assign__cats">
                <legend>{t('Nur diese Kategorien')}</legend>
                {categories.length === 0 && <p className="hint">{t('Es gibt noch keine Kategorien.')}</p>}
                {categories.map((category) => {
                  const checked = entry.category_ids.includes(category.id);
                  return (
                    <label className="check" key={category.id} htmlFor={key + '-c' + String(category.id)}>
                      <input
                        id={key + '-c' + String(category.id)}
                        type="checkbox"
                        checked={checked}
                        disabled={disabled}
                        onChange={(event) =>
                          update(index, { category_ids: event.target.checked ? [...entry.category_ids, category.id] : entry.category_ids.filter((id) => id !== category.id) })
                        }
                      />
                      <span>{category.name}</span>
                    </label>
                  );
                })}
              </fieldset>
            )}
            {ineffective && <p className="field__error">{t('Kategorien wählen oder „Alle Kategorien“ ankreuzen. Ohne Auswahl bleibt die Zuweisung wirkungslos und wird nicht gespeichert.')}</p>}
          </div>
        );
      })}
      {error !== undefined && <p className="field__error">{error}</p>}
      {free.length > 0 && value.length < (catalog.max_assignments ?? 10) && (
        <div className="row">
          <label className="field__label" htmlFor={base + '-add'}>
            {t('Rolle hinzufügen')}</label>
          <select
            id={base + '-add'}
            className="select"
            disabled={disabled}
            value=""
            onChange={(event) => {
              const id = Number.parseInt(event.target.value, 10);
              if (Number.isInteger(id)) {
                onChange([...value, { role_id: id, all_categories: isDangerousRole(catalog, id), category_ids: [] }]);
              }
            }}
          >
            <option value="">{t('Auswählen …')}</option>
            {free.map((role) => (
              <option key={role.id} value={role.id}>
                {role.name}
              </option>
            ))}
          </select>
        </div>
      )}
    </div>
  );
}

/** Zuweisungen als Anfragekörper: nur vollständige Einträge; Admin-Rollen immer mit „alle“. */
export function validAssignments(value: AssignmentInput[]): boolean {
  return value.every((entry) => entry.all_categories || entry.category_ids.length > 0);
}
