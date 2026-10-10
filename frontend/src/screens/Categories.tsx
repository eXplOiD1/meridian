import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Alert } from '../components/Alert';
import { FormField } from '../components/FormField';
import { request } from '../lib/api';
import { errorMessage, fieldErrors } from '../lib/errors';
import type { CategoryUsage, Profile } from '../types';

function plural(n: number, one: string, many: string): string {
  return String(n) + ' ' + (n === 1 ? one : many);
}

/** Folgen des Löschens in Worten (ADR 0005 E9): nur wenn kein Job die Kategorie hat. */
function consequences(c: CategoryUsage): string {
  const parts: string[] = [];
  if (c.assignments > 0) {
    parts.push(
      plural(c.assignments, 'Zuweisung verliert', 'Zuweisungen verlieren') +
        ' diese Kategorie' +
        (c.assignments_ineffective > 0 ? ', ' + String(c.assignments_ineffective) + ' davon ' + (c.assignments_ineffective === 1 ? 'wird' : 'werden') + ' wirkungslos' : ''),
    );
  }
  if (c.internal_targets > 0) {
    parts.push(plural(c.internal_targets, 'Freigabe interner Ziele wird', 'Freigaben interner Ziele werden') + ' gelöscht (nie global)');
  }
  return parts.length === 0 ? 'Es hängen keine Zuweisungen oder Freigaben an dieser Kategorie.' : parts.join('. ') + '.';
}

interface RowProps {
  category: CategoryUsage;
  csrf: string;
  onChanged: () => Promise<void>;
}

function Row({ category, csrf, onChanged }: RowProps) {
  const [mode, setMode] = useState<'view' | 'rename' | 'delete'>('view');
  const [name, setName] = useState(category.name);
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

  const cols = (
    <>
      <td>{category.jobs}</td>
      <td>
        {category.assignments}
        {category.assignments_ineffective > 0 && <span className="hint"> ({category.assignments_ineffective} wirkungslos)</span>}
      </td>
      <td>{category.internal_targets}</td>
    </>
  );

  return (
    <>
      <tr>
        <td>
          {mode === 'rename' ? (
            <form className="inline-edit" onSubmit={(event) => void rename(event)}>
              <FormField label={'Neuer Name für ' + category.name} error={fieldError}>
                {(aria) => <input {...aria} className="input" name="name" autoComplete="off" required autoFocus value={name} onChange={(e) => setName(e.target.value)} />}
              </FormField>
              <button type="submit" className="btn btn--solid btn--small" disabled={busy || name === '' || name === category.name}>
                Speichern
              </button>
              <button
                type="button"
                className="btn btn--ghost btn--small"
                onClick={() => {
                  setMode('view');
                  setName(category.name);
                  setFieldError(undefined);
                }}
              >
                Abbrechen
              </button>
            </form>
          ) : (
            <strong>{category.name}</strong>
          )}
        </td>
        {cols}
        <td>
          <div className="row">
            <button type="button" className="btn btn--ghost btn--small" disabled={busy || mode !== 'view'} aria-label={category.name + ' umbenennen'} onClick={() => setMode('rename')}>
              Umbenennen
            </button>
            <button
              type="button"
              className="btn btn--ghost btn--small btn--danger-text"
              disabled={busy || mode !== 'view' || category.jobs > 0}
              aria-label={category.name + ' löschen'}
              title={category.jobs > 0 ? 'Die Kategorie enthält Jobs. Jobs zuerst verschieben oder löschen.' : undefined}
              onClick={() => void startDelete()}
            >
              Löschen
            </button>
          </div>
          {category.jobs > 0 && <div className="hint">Enthält Jobs: Löschen erst nach dem Verschieben oder Löschen der Jobs.</div>}
          {error !== null && (
            <p className="field__error" role="alert">
              {error}
            </p>
          )}
        </td>
      </tr>
      {mode === 'delete' && preview !== null && (
        <tr>
          <td colSpan={5}>
            <div className="confirm confirm--form" role="group" aria-label={category.name + ' löschen bestätigen'}>
              <span className="confirm__q">Kategorie „{preview.name}“ löschen?</span>
              {preview.jobs > 0 ? (
                <p className="confirm__err" role="alert">
                  Die Kategorie enthält jetzt {plural(preview.jobs, 'Job', 'Jobs')}. Jobs zuerst verschieben oder löschen.
                </p>
              ) : (
                <p className="hint">{consequences(preview)}</p>
              )}
              <div className="row">
                <button type="button" className="btn btn--danger btn--small" disabled={busy || preview.jobs > 0} onClick={() => void remove()}>
                  Endgültig löschen
                </button>
                <button type="button" className="btn btn--ghost btn--small" autoFocus onClick={() => setMode('view')}>
                  Abbrechen
                </button>
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
      <section className="card" aria-label="Kategorien">
        <h2>Kategorien</h2>
        <p className="card__lead">
          Kategorien gruppieren Jobs und begrenzen, was Benutzer sehen und tun dürfen. Umbenennen ändert nichts an Zuweisungen und Freigaben. Löschen geht nur ohne Jobs.
        </p>
        {error !== null && <Alert tone="err">{error}</Alert>}
        <form className="row" onSubmit={(event) => void create(event)}>
          <div className="row__grow">
            <FormField label="Neue Kategorie" error={fieldError}>
              {(aria) => <input {...aria} className="input" name="name" autoComplete="off" value={name} onChange={(e) => setName(e.target.value)} />}
            </FormField>
          </div>
          <button type="submit" className="btn btn--solid" disabled={busy || name === ''}>
            Kategorie anlegen
          </button>
        </form>
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th scope="col">Name</th>
                <th scope="col">Jobs</th>
                <th scope="col">Zuweisungen</th>
                <th scope="col">Freigaben</th>
                <th scope="col">Aktionen</th>
              </tr>
            </thead>
            <tbody>
              {(list ?? []).map((category) => (
                <Row key={category.id} category={category} csrf={csrf} onChanged={load} />
              ))}
              {list !== null && list.length === 0 && (
                <tr>
                  <td colSpan={5} className="hint">
                    Noch keine Kategorien.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
