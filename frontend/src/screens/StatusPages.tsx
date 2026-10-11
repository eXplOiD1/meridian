import { useCallback, useEffect, useState } from 'react';
import { Alert } from '../components/Alert';
import { Confirm } from '../components/Confirm';
import { FormField } from '../components/FormField';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import type { CategoryRef, Monitor, Profile, StatusPage, StatusPageItem } from '../types';
import { t } from '../i18n';

interface Candidate {
  type: 'job' | 'monitor';
  id: number;
  name: string;
}

interface Draft {
  title: string;
  categoryId: string;
  enabled: boolean;
  items: Array<{ key: number; type: 'job' | 'monitor'; id: number; label: string; name: string }>;
}

let nextKey = 1;

function draftOf(page: StatusPage | null, categoryId: string): Draft {
  if (page === null) return { title: '', categoryId, enabled: true, items: [] };
  return {
    title: page.title,
    categoryId: page.category_id === null ? '' : String(page.category_id),
    enabled: page.enabled,
    items: page.items.map((i: StatusPageItem) => ({ key: nextKey++, type: i.type, id: i.id, label: i.label, name: i.name ?? '' })),
  };
}

function PageForm({ draft, setDraft, categories, candidates, errors, busy, submitLabel, onSubmit, onCancel }: {
  draft: Draft;
  setDraft: (d: Draft) => void;
  categories: CategoryRef[];
  candidates: Candidate[];
  errors: Record<string, string>;
  busy: boolean;
  submitLabel: string;
  onSubmit: () => void;
  onCancel?: () => void;
}) {
  const [pick, setPick] = useState('');
  const free = candidates.filter((c) => !draft.items.some((i) => i.type === c.type && i.id === c.id));
  const add = (): void => {
    const found = free.find((c) => c.type + ':' + String(c.id) === pick);
    if (found !== undefined) {
      setDraft({ ...draft, items: [...draft.items, { key: nextKey++, type: found.type, id: found.id, label: found.name, name: found.name }] });
      setPick('');
    }
  };
  return (
    <form
      className="form"
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        onSubmit();
      }}
    >
      <div className="grid-2">
        <FormField label={t('Titel')} error={errors.title} hint={t('Steht oben auf der öffentlichen Seite.')}>
          {(aria) => <input {...aria} className="input" name="page-title" autoComplete="off" maxLength={80} value={draft.title} onChange={(e) => setDraft({ ...draft, title: e.target.value })} />}
        </FormField>
        <FormField label={t('Kategorie der Seite')} error={errors.category_id} hint={t('Bestimmt, wer die Seite bearbeiten darf.')}>
          {(aria) => (
            <select {...aria} className="input" name="page-category" value={draft.categoryId} onChange={(e) => setDraft({ ...draft, categoryId: e.target.value })}>
              {categories.length === 0 && <option value="">{t('Keine')}</option>}
              {categories.map((c) => (
                <option key={c.id} value={String(c.id)}>
                  {c.name}
                </option>
              ))}
            </select>
          )}
        </FormField>
      </div>
      <label className="check">
        <input type="checkbox" checked={draft.enabled} onChange={(e) => setDraft({ ...draft, enabled: e.target.checked })} />
        <span>{t('Seite ist erreichbar (ausgeschaltet antwortet der Link wie ein unbekannter)')}</span>
      </label>
      <fieldset className="statusitems">
        <legend className="field__label">{t('Einträge')}</legend>
        <p className="hint">{t('Die Seite zeigt nur den Anzeigenamen, den Zustand und den Zeitpunkt, nie den Namen des Jobs, Adressen oder Ausgaben. Den Anzeigenamen wählst du selbst.')}</p>
        {errors.items !== undefined && <p className="field__error">{errors.items}</p>}
        {draft.items.length === 0 && <p className="hint">{t('Noch keine Einträge.')}</p>}
        {draft.items.map((item, index) => (
          <div key={item.key} className="row statusitems__row">
            <span className="badge">{item.type === 'job' ? t('Job') : t('Überwachung')}</span>
            <input
              className="input"
              aria-label={t('Anzeigename von {name}', { name: item.name === '' ? t('Eintrag {n}', { n: index + 1 }) : item.name })}
              maxLength={80}
              value={item.label}
              onChange={(e) => setDraft({ ...draft, items: draft.items.map((x) => (x.key === item.key ? { ...x, label: e.target.value } : x)) })}
            />
            <span className="muted statusitems__name">{item.name}</span>
            <button type="button" className="btn btn--ghost btn--small" aria-label={t('Nach oben')} disabled={index === 0} onClick={() => {
              const items = [...draft.items];
              const moved = items.splice(index, 1)[0];
              if (moved !== undefined) items.splice(index - 1, 0, moved);
              setDraft({ ...draft, items });
            }}>
              ↑
            </button>
            <button type="button" className="btn btn--ghost btn--small btn--danger-text" onClick={() => setDraft({ ...draft, items: draft.items.filter((x) => x.key !== item.key) })}>
              {t('Entfernen')}</button>
            {errors['items.' + String(index)] !== undefined && <span className="field__error">{errors['items.' + String(index)]}</span>}
            {errors['items.' + String(index) + '.label'] !== undefined && <span className="field__error">{errors['items.' + String(index) + '.label']}</span>}
          </div>
        ))}
        <div className="row">
          <select className="input" aria-label={t('Eintrag hinzufügen')} value={pick} onChange={(e) => setPick(e.target.value)}>
            <option value="">{t('Job oder Überwachung wählen …')}</option>
            {free.map((c) => (
              <option key={c.type + ':' + String(c.id)} value={c.type + ':' + String(c.id)}>
                {(c.type === 'job' ? t('Job: ') : t('Überwachung: ')) + c.name}
              </option>
            ))}
          </select>
          <button type="button" className="btn btn--ghost" disabled={pick === ''} onClick={add}>
            {t('Hinzufügen')}</button>
        </div>
      </fieldset>
      <div className="row">
        <button type="submit" className="btn btn--solid" disabled={busy || draft.title.trim() === ''}>
          {submitLabel}
        </button>
        {onCancel !== undefined && (
          <button type="button" className="btn btn--ghost" onClick={onCancel}>
            {t('Abbrechen')}</button>
        )}
      </div>
    </form>
  );
}

/** Öffentliche Statusseiten (ADR 0012): eine Seite mit geheimem Link, die nur Anzeigenamen und Zustände zeigt. */
export function StatusPages({ profile }: { profile: Profile }) {
  const csrf = profile.csrf_token;
  const [pages, setPages] = useState<StatusPage[] | null>(null);
  const [categories, setCategories] = useState<CategoryRef[]>([]);
  const [candidates, setCandidates] = useState<Candidate[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [draft, setDraft] = useState<Draft>(draftOf(null, ''));
  const [editing, setEditing] = useState<{ id: number; draft: Draft } | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async (): Promise<void> => {
    try {
      setPages((await request<{ pages: StatusPage[] }>('GET', '/api/status-pages')).pages);
      setLoadError(null);
    } catch (caught) {
      setLoadError(errorMessage(caught));
    }
  }, []);

  useEffect(() => {
    void load();
    request<{ categories: CategoryRef[] }>('GET', '/api/categories?permission=status_pages.edit')
      .then((reply) => {
        setCategories(reply.categories);
        setDraft((current) => (current.categoryId === '' && reply.categories[0] !== undefined ? { ...current, categoryId: String(reply.categories[0].id) } : current));
      })
      .catch(() => setCategories([]));
    Promise.all([
      request<{ jobs: Array<{ id: number; name: string }> }>('GET', '/api/jobs').catch(() => ({ jobs: [] })),
      request<{ monitors: Monitor[] }>('GET', '/api/monitors').catch(() => ({ monitors: [] })),
    ]).then(([jobs, monitors]) => {
      setCandidates([
        ...jobs.jobs.map((j): Candidate => ({ type: 'job', id: j.id, name: j.name })),
        ...monitors.monitors.map((m): Candidate => ({ type: 'monitor', id: m.id, name: m.name })),
      ]);
    });
  }, [load]);

  async function submit(method: 'POST' | 'PUT', path: string, d: Draft): Promise<boolean> {
    setBusy(true);
    setErrors({});
    setFormError(null);
    try {
      await request(method, path, {
        csrf,
        body: {
          title: d.title.trim(),
          category_id: d.categoryId === '' ? null : Number.parseInt(d.categoryId, 10),
          is_enabled: d.enabled,
          items: d.items.map((i) => ({ type: i.type, id: i.id, label: i.label.trim() })),
        },
      });
      await load();
      return true;
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 422) {
        setErrors(caught.fields);
        setFormError(t('Bitte die markierten Felder korrigieren.'));
      } else {
        setFormError(errorMessage(caught));
      }
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function act(run: () => Promise<void>): Promise<void> {
    setBusy(true);
    setFormError(null);
    try {
      await run();
      await load();
    } catch (caught) {
      setFormError(errorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <section className="card" aria-label={t('Statusseiten')}>
        <h2>{t('Statusseiten')}</h2>
        <p className="card__lead">
          {t('Eine Seite zum Weitergeben, auch an Menschen ohne Konto: sie zeigt, ob ausgewählte Jobs und Überwachungen laufen. Der Link ist geheim und nicht erratbar; wer ihn kennt, sieht die Seite. Bei Bedarf einen neuen Link erzeugen, der alte gilt dann sofort nicht mehr.')}</p>
        {loadError !== null && <Alert tone="err">{loadError}</Alert>}
        {formError !== null && <Alert tone="err">{formError}</Alert>}
        {pages !== null && pages.length === 0 && <p className="card__lead">{t('Noch keine Statusseite angelegt.')}</p>}
        {pages !== null && pages.length > 0 && (
          <div className="table-wrap">
            <table className="table">
              <caption className="visually-hidden">{t('Statusseiten')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('Titel')}</th>
                  <th scope="col">{t('Einträge')}</th>
                  <th scope="col">{t('Link')}</th>
                  <th scope="col">{t('Aktion')}</th>
                </tr>
              </thead>
              <tbody>
                {pages.map((page) => {
                  const url = window.location.origin + page.path;
                  return (
                    <tr key={page.id}>
                      <td>
                        {page.title}
                        {!page.enabled && <div className="hint">{t('ausgeschaltet')}</div>}
                        {page.category !== null && <div className="hint">{page.category}</div>}
                      </td>
                      <td>{page.items.length}</td>
                      <td>
                        <a href={page.path} target="_blank" rel="noopener noreferrer" className="mono">
                          {url}
                        </a>
                      </td>
                      <td>
                        <div className="row">
                          <button type="button" className="btn btn--ghost btn--small" aria-label={page.title + ' bearbeiten'} onClick={() => setEditing({ id: page.id, draft: draftOf(page, '') })}>
                            {t('Bearbeiten')}</button>
                          <Confirm label={t('Neuer Link')} question={t('Neuen Link erzeugen? Der alte gilt sofort nicht mehr.')} confirmLabel={t('Ja, erneuern')} busy={busy} accessibleName={t('Link von {name} erneuern', { name: page.title })} onConfirm={() => void act(async () => {
                            await request('POST', '/api/status-pages/' + String(page.id) + '/link', { csrf });
                          })} />
                          <Confirm label={t('Löschen')} question={t('Statusseite löschen?')} confirmLabel={t('Ja, löschen')} busy={busy} accessibleName={t('{name} löschen', { name: page.title })} onConfirm={() => void act(async () => {
                            await request('DELETE', '/api/status-pages/' + String(page.id), { csrf });
                          })} />
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        {editing !== null && (
          <div className="replace">
            <h3>{t('Statusseite bearbeiten')}</h3>
            <PageForm
              draft={editing.draft}
              setDraft={(d) => setEditing({ id: editing.id, draft: d })}
              categories={categories}
              candidates={candidates}
              errors={errors}
              busy={busy}
              submitLabel={t('Speichern')}
              onSubmit={() => void submit('PUT', '/api/status-pages/' + String(editing.id), editing.draft).then((ok) => ok && setEditing(null))}
              onCancel={() => {
                setEditing(null);
                setErrors({});
              }}
            />
          </div>
        )}
      </section>
      {editing === null && (
        <section className="card" aria-label={t('Statusseite anlegen')}>
          <h2>{t('Statusseite anlegen')}</h2>
          <PageForm
            draft={draft}
            setDraft={setDraft}
            categories={categories}
            candidates={candidates}
            errors={errors}
            busy={busy}
            submitLabel="Anlegen"
            onSubmit={() => void submit('POST', '/api/status-pages', draft).then((ok) => ok && setDraft(draftOf(null, draft.categoryId)))}
          />
        </section>
      )}
    </>
  );
}
