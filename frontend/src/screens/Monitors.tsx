import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Alert } from '../components/Alert';
import { Confirm } from '../components/Confirm';
import { FormField } from '../components/FormField';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatTime } from '../lib/format';
import { canEditHttp } from '../lib/permissions';
import type { CategoryRef, Monitor, MonitorState, Profile } from '../types';
import { t } from '../i18n';

const UNITS = [
  { value: 'minutes', label: 'Minuten', seconds: 60 },
  { value: 'hours', label: 'Stunden', seconds: 3600 },
  { value: 'days', label: 'Tage', seconds: 86400 },
] as const;

type Unit = (typeof UNITS)[number]['value'];

const STATE: Record<MonitorState, { label: string; dot: string }> = {
  up: { label: 'Meldet sich', dot: 'ok' },
  down: { label: 'Kein Lebenszeichen', dot: 'err' },
  waiting: { label: 'Wartet auf den ersten Ping', dot: 'warn' },
  paused: { label: 'Pausiert', dot: 'info' },
};

/** Zerlegt Sekunden in die größte glatte Einheit (für das Bearbeiten). */
function split(seconds: number): { value: string; unit: Unit } {
  if (seconds % 86400 === 0) return { value: String(seconds / 86400), unit: 'days' };
  if (seconds % 3600 === 0) return { value: String(seconds / 3600), unit: 'hours' };
  return { value: String(Math.round(seconds / 60)), unit: 'minutes' };
}

function describe(seconds: number): string {
  const { value, unit } = split(seconds);
  return value + ' ' + t(UNITS.find((u) => u.value === unit)?.label ?? '');
}

function toSeconds(value: string, unit: Unit): number | null {
  if (!/^\d{1,6}$/.test(value)) return null;
  return Number.parseInt(value, 10) * (UNITS.find((u) => u.value === unit)?.seconds ?? 60);
}

interface Issued {
  name: string;
  token: string;
}

/** Zeigt das frisch erzeugte Ping-Token genau einmal; danach nur noch erneuern. */
function IssuedToken({ issued, onClose }: { issued: Issued; onClose: () => void }) {
  const url = window.location.origin + '/ping/' + issued.token;
  return (
    <Alert tone="warn">
      <strong>{t('Ping-Adresse für „{name}“ (wird nur jetzt angezeigt):', { name: issued.name })}</strong>
      <pre className="runout" aria-label={t('Ping-Adresse')}>{url}</pre>
      {t('Am Ende deines Skripts aufrufen, z. B.')} <code>curl -fsS -m 10 {url}</code> {t('(GET oder POST). Wer die Adresse kennt, kann Lebenszeichen senden: nicht weitergeben.')}
      <div className="row">
        <button type="button" className="btn btn--ghost btn--small" onClick={onClose}>
          {t('Habe ich gespeichert, ausblenden')}</button>
      </div>
    </Alert>
  );
}

interface Draft {
  name: string;
  value: string;
  unit: Unit;
  graceValue: string;
  graceUnit: Unit;
  categoryId: string;
  notifyDown: boolean;
  notifyUp: boolean;
  notifyChannels: 'all' | 'none';
}

function emptyDraft(categoryId: string): Draft {
  return { name: '', value: '1', unit: 'days', graceValue: '15', graceUnit: 'minutes', categoryId, notifyDown: true, notifyUp: true, notifyChannels: 'all' };
}

function draftOf(m: Monitor): Draft {
  const interval = split(m.interval_seconds);
  const grace = split(m.grace_seconds);
  return { name: m.name, value: interval.value, unit: interval.unit, graceValue: m.grace_seconds === 0 ? '0' : grace.value, graceUnit: grace.unit, categoryId: m.category_id === null ? '' : String(m.category_id), notifyDown: m.notify_on_down, notifyUp: m.notify_on_up, notifyChannels: m.notify_channels };
}

function MonitorForm({ draft, setDraft, categories, errors, busy, submitLabel, onSubmit, onCancel }: {
  draft: Draft;
  setDraft: (d: Draft) => void;
  categories: CategoryRef[];
  errors: Record<string, string>;
  busy: boolean;
  submitLabel: string;
  onSubmit: () => void;
  onCancel?: () => void;
}) {
  const unitSelect = (value: Unit, onChange: (u: Unit) => void, label: string) => (
    <select className="input input--short" aria-label={label} value={value} onChange={(e) => onChange(e.target.value as Unit)}>
      {UNITS.map((u) => (
        <option key={u.value} value={u.value}>
          {t(u.label)}
        </option>
      ))}
    </select>
  );
  return (
    <form
      className="form"
      noValidate
      onSubmit={(event: FormEvent) => {
        event.preventDefault();
        onSubmit();
      }}
    >
      <div className="grid-2">
        <FormField label={t('Name')} error={errors.name} hint={t('Woran du den Lauf erkennst, z. B. „Nacht-Backup“.')}>
          {(aria) => <input {...aria} className="input" name="monitor-name" autoComplete="off" maxLength={80} value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} />}
        </FormField>
        <FormField label={t('Kategorie')} error={errors.category_id}>
          {(aria) => (
            <select {...aria} className="input" name="monitor-category" value={draft.categoryId} onChange={(e) => setDraft({ ...draft, categoryId: e.target.value })}>
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
      <div className="grid-2">
        <FormField label={t('Erwartet alle …')} error={errors.interval_seconds} hint={t('Wie oft das Lebenszeichen spätestens kommen soll (mindestens 1 Minute).')}>
          {(aria) => (
            <div className="row">
              <input {...aria} className="input input--short" name="monitor-interval" inputMode="numeric" value={draft.value} onChange={(e) => setDraft({ ...draft, value: e.target.value })} />
              {unitSelect(draft.unit, (unit) => setDraft({ ...draft, unit }), 'Einheit des Intervalls')}
            </div>
          )}
        </FormField>
        <FormField label={t('Toleranz')} error={errors.grace_seconds} hint={t('Zusätzliche Wartezeit, bevor es als Ausfall gilt.')}>
          {(aria) => (
            <div className="row">
              <input {...aria} className="input input--short" name="monitor-grace" inputMode="numeric" value={draft.graceValue} onChange={(e) => setDraft({ ...draft, graceValue: e.target.value })} />
              {unitSelect(draft.graceUnit, (graceUnit) => setDraft({ ...draft, graceUnit }), t('Einheit der Toleranz'))}
            </div>
          )}
        </FormField>
      </div>
      <label className="check">
        <input type="checkbox" checked={draft.notifyDown} onChange={(e) => setDraft({ ...draft, notifyDown: e.target.checked })} />
        <span>{t('Benachrichtigen, wenn das Lebenszeichen ausbleibt')}</span>
      </label>
      <label className="check">
        <input type="checkbox" checked={draft.notifyUp} onChange={(e) => setDraft({ ...draft, notifyUp: e.target.checked })} />
        <span>{t('Benachrichtigen, wenn es sich wieder meldet')}</span>
      </label>
      <label className="check">
        <input type="checkbox" checked={draft.notifyChannels === 'none'} onChange={(e) => setDraft({ ...draft, notifyChannels: e.target.checked ? 'none' : 'all' })} />
        <span>{t('Keine Kanäle benutzen (nur Anzeige hier und in der Übersicht)')}</span>
      </label>
      <div className="row">
        <button type="submit" className="btn btn--solid" disabled={busy || draft.name.trim() === ''}>
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

function payload(draft: Draft): { body: Record<string, unknown> } | { error: Record<string, string> } {
  const interval = toSeconds(draft.value, draft.unit);
  const grace = toSeconds(draft.graceValue, draft.graceUnit);
  const error: Record<string, string> = {};
  if (interval === null) error.interval_seconds = 'Zahl eingeben (ganze Zahl).';
  if (grace === null) error.grace_seconds = 'Zahl eingeben (ganze Zahl, 0 erlaubt).';
  if (Object.keys(error).length > 0) return { error };
  return { body: { name: draft.name.trim(), category_id: draft.categoryId === '' ? null : Number.parseInt(draft.categoryId, 10), interval_seconds: interval, grace_seconds: grace, notify_on_down: draft.notifyDown, notify_on_up: draft.notifyUp, notify_channels: draft.notifyChannels } };
}

/** Herzschlag-Überwachung: Skripte melden sich per Ping, bleibt es aus, wird gemeldet (ADR 0011). */
export function Monitors({ profile }: { profile: Profile }) {
  const csrf = profile.csrf_token;
  const [monitors, setMonitors] = useState<Monitor[] | null>(null);
  const [categories, setCategories] = useState<CategoryRef[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [draft, setDraft] = useState<Draft>(emptyDraft(''));
  const [editing, setEditing] = useState<{ id: number; draft: Draft } | null>(null);
  const [issued, setIssued] = useState<Issued | null>(null);
  const [busy, setBusy] = useState(false);
  const mayCreate = canEditHttp(profile);

  const load = useCallback(async (): Promise<void> => {
    try {
      setMonitors((await request<{ monitors: Monitor[] }>('GET', '/api/monitors')).monitors);
      setLoadError(null);
    } catch (caught) {
      setLoadError(errorMessage(caught));
    }
  }, []);

  useEffect(() => {
    void load();
    request<{ categories: CategoryRef[] }>('GET', '/api/categories?permission=jobs.edit_http')
      .then((reply) => {
        setCategories(reply.categories);
        setDraft((current) => (current.categoryId === '' && reply.categories[0] !== undefined ? { ...current, categoryId: String(reply.categories[0].id) } : current));
      })
      .catch(() => setCategories([]));
  }, [load]);

  async function submit(method: 'POST' | 'PUT', path: string, d: Draft): Promise<boolean> {
    const built = payload(d);
    if ('error' in built) {
      setErrors(built.error);
      return false;
    }
    setBusy(true);
    setErrors({});
    setFormError(null);
    try {
      const reply = await request<{ monitor: Monitor; ping_token?: string }>(method, path, { csrf, body: built.body });
      if (reply.ping_token !== undefined) setIssued({ name: reply.monitor.name, token: reply.ping_token });
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
      <section className="card" aria-label={t('Überwachungen')}>
        <h2>{t('Lebenszeichen')}</h2>
        <p className="card__lead">
          {t('Für Dinge, die Meridian nicht selbst startet (ein Skript auf dem Server, eine Sicherung, ein Router): sie rufen am Ende eine Adresse auf. Bleibt der Aufruf aus, meldet Meridian den Ausfall und danach die Rückkehr.')}</p>
        {loadError !== null && <Alert tone="err">{loadError}</Alert>}
        {formError !== null && <Alert tone="err">{formError}</Alert>}
        {issued !== null && <IssuedToken issued={issued} onClose={() => setIssued(null)} />}
        {monitors !== null && monitors.length === 0 && <p className="card__lead">{t('Noch keine Überwachung angelegt.')}</p>}
        {monitors !== null && monitors.length > 0 && (
          <div className="table-wrap">
            <table className="table">
              <caption className="visually-hidden">{t('Überwachungen')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('Name')}</th>
                  <th scope="col">{t('Zustand')}</th>
                  <th scope="col">{t('Erwartet alle')}</th>
                  <th scope="col">{t('Letztes Lebenszeichen')}</th>
                  <th scope="col">{t('Aktion')}</th>
                </tr>
              </thead>
              <tbody>
                {monitors.map((m) => (
                  <tr key={m.id}>
                    <td>
                      {m.name}
                      {m.category !== null && <div className="hint">{m.category}</div>}
                    </td>
                    <td>
                      <span className="runstate">
                        <span className={'dot dot--' + STATE[m.state].dot} aria-hidden="true" />
                        {t(STATE[m.state].label)}
                      </span>
                    </td>
                    <td>{describe(m.interval_seconds)}</td>
                    <td>{m.last_ping_at === null ? <span className="muted">{t('noch nie')}</span> : formatTime(m.last_ping_at)}</td>
                    <td>
                      {m.can_edit ? (
                        <div className="row">
                          <button type="button" className="btn btn--ghost btn--small" aria-label={m.name + ' bearbeiten'} onClick={() => setEditing({ id: m.id, draft: draftOf(m) })}>
                            {t('Bearbeiten')}</button>
                          <Confirm label={t('Neue Adresse')} question={t('Neue Ping-Adresse erzeugen? Die alte gilt sofort nicht mehr.')} confirmLabel={t('Ja, erneuern')} busy={busy} accessibleName={t('Ping-Adresse von {name} erneuern', { name: m.name })} onConfirm={() => void act(async () => {
                            const reply = await request<{ ping_token: string }>('POST', '/api/monitors/' + String(m.id) + '/token', { csrf });
                            setIssued({ name: m.name, token: reply.ping_token });
                          })} />
                          <Confirm label={t('Löschen')} question={t('Überwachung löschen?')} confirmLabel={t('Ja, löschen')} busy={busy} accessibleName={t('{name} löschen', { name: m.name })} onConfirm={() => void act(async () => {
                            await request('DELETE', '/api/monitors/' + String(m.id), { csrf });
                          })} />
                        </div>
                      ) : (
                        <span className="muted">{t('nur lesen')}</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {editing !== null && (
          <div className="replace">
            <h3>{t('Überwachung bearbeiten')}</h3>
            <MonitorForm
              draft={editing.draft}
              setDraft={(d) => setEditing({ id: editing.id, draft: d })}
              categories={categories}
              errors={errors}
              busy={busy}
              submitLabel={t('Speichern')}
              onSubmit={() => void submit('PUT', '/api/monitors/' + String(editing.id), editing.draft).then((ok) => ok && setEditing(null))}
              onCancel={() => {
                setEditing(null);
                setErrors({});
              }}
            />
          </div>
        )}
      </section>
      {mayCreate && editing === null && (
        <section className="card" aria-label={t('Überwachung anlegen')}>
          <h2>{t('Überwachung anlegen')}</h2>
          <MonitorForm
            draft={draft}
            setDraft={setDraft}
            categories={categories}
            errors={errors}
            busy={busy}
            submitLabel="Anlegen"
            onSubmit={() => void submit('POST', '/api/monitors', draft).then((ok) => ok && setDraft(emptyDraft(draft.categoryId)))}
          />
        </section>
      )}
    </>
  );
}
