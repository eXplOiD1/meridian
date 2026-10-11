import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Alert } from '../components/Alert';
import { FormField } from '../components/FormField';
import { request } from '../lib/api';
import { errorMessage, fieldErrors } from '../lib/errors';
import type { CategoryUsage, Profile } from '../types';
import { t } from '../i18n';

function plural(n: number, one: string, many: string): string {
  return String(n) + ' ' + t(n === 1 ? one : many);
}

/** Folgen des Löschens in Worten (ADR 0005 E9): nur wenn kein Job die Kategorie hat. */
function consequences(c: CategoryUsage): string {
  const parts: string[] = [];
  if (c.assignments > 0) {
    parts.push(
      plural(c.assignments, t('Zuweisung verliert'), t('Zuweisungen verlieren')) +
        ' ' + t('diese Kategorie') +
        (c.assignments_ineffective > 0 ? ', ' + t('{n} davon werden wirkungslos', { n: c.assignments_ineffective }) : ''),
    );
  }
  if (c.internal_targets > 0) {
    parts.push(plural(c.internal_targets, t('Freigabe interner Ziele wird'), t('Freigaben interner Ziele werden')) + ' ' + t('gelöscht (nie global)'));
  }
  return parts.length === 0 ? t('Es hängen keine Zuweisungen oder Freigaben an dieser Kategorie.') : parts.join('. ') + '.';
}

interface RowProps {
  category: CategoryUsage;
  csrf: string;
  onChanged: () => Promise<void>;
}

function Row({ category, csrf, onChanged }: RowProps) {
  const [mode, setMode] = useState<'view' | 'rename' | 'delete' | 'recipients'>('view');
  const [name, setName] = useState(category.name);
  const [recipients, setRecipients] = useState((category.recipients ?? []).join('\n'));
  const [preview, setPreview] = useState<CategoryUsage | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | undefined>(undefined);

  async function rename(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setFieldError(undefined);
    try {
      await request('PUT', '/api/categories/manage/' + String(category.id), { csrf, body: { name } });
      setMode('view');
      await onChanged();
    } catch (caught) {
      setFieldError(fieldErrors(caught).name);
      setError(fieldErrors(caught).name === undefined ? errorMessage(caught) : null);
    } finally {
      setBusy(false);
    }
  }

  async function saveRecipients(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setFieldError(undefined);
    try {
      await request('PUT', '/api/categories/manage/' + String(category.id) + '/recipients', { csrf, body: { recipients: recipients.split(/\r?\n/).map((address) => address.trim()).filter(Boolean) } });
      setMode('view');
      await onChanged();
    } catch (caught) {
      setFieldError(fieldErrors(caught).recipients);
      setError(fieldErrors(caught).recipients === undefined ? errorMessage(caught) : null);
    } finally {
      setBusy(false);
    }
  }

  async function startDelete(): Promise<void> {
    setBusy(true);
    setError(null);
    try {
      // Frische Zahlen für die Vorschau, nicht die der Liste von vor einer Weile.
      const reply = await request<{ category: CategoryUsage }>('GET', '/api/categories/manage/' + String(category.id) + '/impact');
      setPreview(reply.category);
      setMode('delete');
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  async function remove(): Promise<void> {
    setBusy(true);
    setError(null);
    try {
      await request('DELETE', '/api/categories/manage/' + String(category.id), { csrf });
      await onChanged();
    } catch (caught) {
      setError(errorMessage(caught));
      setMode('view');
    } finally {
      setBusy(false);
    }
  }

  const blocked = category.jobs > 0 || category.watchers > 0;
  const cols = (
    <>
      <td>{category.jobs}</td>
      <td>
        {category.assignments}
        {category.assignments_ineffective > 0 && <span className="hint"> ({category.assignments_ineffective} {t('wirkungslos')})</span>}
      </td>
      <td>{category.internal_targets}</td>
    </>
  );

  return (
    <>
      <tr>
        <td>
          {mode === 'rename' ? (
            <form onSubmit={(event) => void rename(event)}>
              <FormField label={t('Neuer Name für {name}', { name: category.name })} error={fieldError}>
                {(aria) => <div className="row category-rename">
                  <input {...aria} className="input" name="name" autoComplete="off" required autoFocus value={name} onChange={(e) => setName(e.target.value)} />
                  <button type="submit" className="btn btn--solid btn--small" disabled={busy || name === '' || name === category.name}>{t('Speichern')}</button>
                  <button type="button" className="btn btn--ghost btn--small" disabled={busy} onClick={() => { setMode('view'); setName(category.name); setFieldError(undefined); }}>{t('Abbrechen')}</button>
                </div>}
              </FormField>
            </form>
          ) : (
            <strong>{category.name}</strong>
          )}
        </td>
        {cols}
        <td>
          <div className="row">
            <button type="button" className="btn btn--ghost btn--small" disabled={busy || mode !== 'view'} aria-label={category.name + ' umbenennen'} onClick={() => setMode('rename')}>
              {t('Umbenennen')}</button>
            <button type="button" className="btn btn--ghost btn--small" disabled={busy || mode !== 'view'} onClick={() => { setRecipients((category.recipients ?? []).join('\n')); setFieldError(undefined); setError(null); setMode('recipients'); }}>
              {t('E-Mail-Empfänger')}</button>
            <button
              type="button"
              className="btn btn--ghost btn--small btn--danger-text"
              disabled={busy || mode !== 'view' || blocked}
              aria-label={t('{name} löschen', { name: category.name })}
              title={blocked ? t('Die Kategorie enthält Jobs, Überwachungen oder Statusseiten. Zuerst verschieben oder löschen.') : undefined}
              onClick={() => void startDelete()}
            >
              {t('Löschen')}</button>
          </div>
          {blocked && <div className="hint">{t('Enthält Jobs, Überwachungen oder Statusseiten: Löschen erst nach dem Verschieben oder Löschen.')}</div>}
          {error !== null && (
            <p className="field__error" role="alert">
              {error}
            </p>
          )}
        </td>
      </tr>
      {mode === 'recipients' && (
        <tr>
          <td colSpan={5}>
            <form className="stack" onSubmit={(event) => void saveRecipients(event)}>
              <FormField label={t('E-Mail-Empfänger für Kategorie „{name}“', { name: category.name })} error={fieldError} hint={t('Eine Adresse pro Zeile, höchstens 10. Leer = Empfänger des SMTP-Kanals. Jobs mit eigenen Empfängern haben Vorrang.')}>
                {(aria) => <textarea {...aria} className="input" name="category-recipients" rows={3} autoFocus value={recipients} onChange={(e) => setRecipients(e.target.value)} />}
              </FormField>
              <div className="row">
                <button type="submit" className="btn btn--solid btn--small" disabled={busy}>{t('Speichern')}</button>
                <button type="button" className="btn btn--ghost btn--small" disabled={busy} onClick={() => setMode('view')}>{t('Abbrechen')}</button>
              </div>
            </form>
          </td>
        </tr>
      )}
      {mode === 'delete' && preview !== null && (
        <tr>
          <td colSpan={5}>
            <div className="confirm confirm--form" role="group" aria-label={t('Kategorie „{name}“ löschen?', { name: category.name })}>
              <span className="confirm__q">{t('Kategorie „{name}“ löschen?', { name: preview.name })}</span>
              {preview.jobs > 0 || preview.watchers > 0 ? (
                <p className="confirm__err" role="alert">
                  {t('Die Kategorie enthält jetzt {what}. Zuerst verschieben oder löschen.', { what: preview.jobs > 0 ? plural(preview.jobs, t('Job'), t('Jobs')) : plural(preview.watchers, t('Überwachung oder Statusseite'), t('Überwachungen oder Statusseiten')) })}
                </p>
              ) : (
                <p className="hint">{consequences(preview)}</p>
              )}
              <div className="row">
                <button type="button" className="btn btn--danger btn--small" disabled={busy || preview.jobs > 0 || preview.watchers > 0} onClick={() => void remove()}>
                  {t('Endgültig löschen')}</button>
                <button type="button" className="btn btn--ghost btn--small" autoFocus onClick={() => setMode('view')}>
                  {t('Abbrechen')}</button>
              </div>
            </div>
          </td>
        </tr>
      )}
    </>
  );
}

export function Categories({ profile }: { profile: Profile }) {
  const csrf = profile.csrf_token;
  const [list, setList] = useState<CategoryUsage[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [name, setName] = useState('');
  const [fieldError, setFieldError] = useState<string | undefined>(undefined);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async (): Promise<void> => {
    try {
      setList((await request<{ categories: CategoryUsage[] }>('GET', '/api/categories/manage')).categories);
      setError(null);
    } catch (caught) {
      setError(errorMessage(caught));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function create(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setFieldError(undefined);
    setError(null);
    try {
      await request('POST', '/api/categories/manage', { csrf, body: { name } });
      setName('');
      await load();
    } catch (caught) {
      const named = fieldErrors(caught).name;
      setFieldError(named);
      if (named === undefined) {
        setError(errorMessage(caught));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="settings">
      <section className="card" aria-label={t('Kategorien')}>
        <h2>{t('Kategorien')}</h2>
        <p className="card__lead">
          {t('Kategorien gruppieren Jobs und begrenzen, was Benutzer sehen und tun dürfen. Umbenennen ändert nichts an Zuweisungen und Freigaben. Löschen geht nur ohne Jobs.')}</p>
        {error !== null && <Alert tone="err">{error}</Alert>}
        <form onSubmit={(event) => void create(event)}>
          <FormField label={t('Neue Kategorie')} error={fieldError}>
            {(aria) => <div className="row category-create">
              <div className="row__grow">
                <input {...aria} className="input" name="name" autoComplete="off" value={name} onChange={(e) => setName(e.target.value)} />
              </div>
              <button type="submit" className="btn btn--solid" disabled={busy || name === ''}>
                {t('Kategorie anlegen')}</button>
            </div>}
          </FormField>
        </form>
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th scope="col">{t('Name')}</th>
                <th scope="col">{t('Jobs')}</th>
                <th scope="col">{t('Zuweisungen')}</th>
                <th scope="col">{t('Freigaben')}</th>
                <th scope="col">{t('Aktionen')}</th>
              </tr>
            </thead>
            <tbody>
              {(list ?? []).map((category) => (
                <Row key={category.id} category={category} csrf={csrf} onChanged={load} />
              ))}
              {list !== null && list.length === 0 && (
                <tr>
                  <td colSpan={5} className="hint">
                    {t('Noch keine Kategorien.')}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
