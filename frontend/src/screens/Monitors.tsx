import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Alert } from '../components/Alert';
import { Confirm } from '../components/Confirm';
import { FormField } from '../components/FormField';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatTime } from '../lib/format';
import { canEditHttp } from '../lib/permissions';
import type { CategoryRef, Monitor, MonitorKind, MonitorState, NotificationChannelName, Profile } from '../types';
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

/** Aktive Prüfungen sagen „erreichbar/ausgefallen“ statt „meldet sich“. */
const ACTIVE_STATE: Record<MonitorState, { label: string; dot: string }> = {
  up: { label: 'Erreichbar', dot: 'ok' },
  down: { label: 'Ausgefallen', dot: 'err' },
  waiting: { label: 'Wartet auf die erste Prüfung', dot: 'warn' },
  paused: { label: 'Pausiert', dot: 'info' },
};

const KINDS: ReadonlyArray<{ value: MonitorKind; label: string }> = [
  { value: 'heartbeat', label: 'Lebenszeichen (Skript meldet sich)' },
  { value: 'http', label: 'Web-Adresse (HTTP/HTTPS)' },
  { value: 'tcp', label: 'Netzwerk-Port (TCP)' },
  { value: 'ping', label: 'Ping (ICMP)' },
];

const KIND_SHORT: Record<MonitorKind, string> = { heartbeat: 'Lebenszeichen', http: 'Web', tcp: 'Port', ping: 'Ping' };

const TARGET_PLACEHOLDER: Record<'http' | 'tcp' | 'ping', string> = { http: 'https://www.example.org/health', tcp: 'db.example.org:5432', ping: 'router.example.org' };
const TARGET_HINT: Record<'http' | 'tcp' | 'ping', string> = {
  http: 'Die Adresse wird verschlüsselt gespeichert und nie wieder angezeigt; in Listen steht nur der Server. Weiterleitungen werden nicht verfolgt, das Zertifikat wird immer geprüft.',
  tcp: 'Host und Port, z. B. db.example.org:5432. Geprüft wird, ob sich der Port öffnen lässt.',
  ping: 'Nur der Host oder die IP-Adresse. Braucht das Programm „ping“ im Dienst worker-http.',
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

interface TestResult {
  label: string;
  ok: boolean;
  latency: number | null;
  note: string | null;
}

function TestResultAlert({ result }: { result: TestResult }) {
  return (
    <Alert tone={result.ok ? 'ok' : 'err'}>
      <strong>{result.label}:</strong> {result.ok ? t('erreichbar') + (result.latency !== null ? ' (' + String(result.latency) + ' ms)' : '') : result.note}
    </Alert>
  );
}

interface Draft {
  kind: MonitorKind;
  target: string;
  expectedStatus: string;
  failAfter: string;
  name: string;
  value: string;
  unit: Unit;
  graceValue: string;
  graceUnit: Unit;
  categoryId: string;
  notifyDown: boolean;
  notifyUp: boolean;
  notifyChannels: 'all' | 'selected' | 'none';
  channelIds: number[];
}

function emptyDraft(categoryId: string): Draft {
  return { kind: 'heartbeat', target: '', expectedStatus: '200-399', failAfter: '3', name: '', value: '1', unit: 'days', graceValue: '15', graceUnit: 'minutes', categoryId, notifyDown: true, notifyUp: true, notifyChannels: 'all', channelIds: [] };
}

function draftOf(m: Monitor): Draft {
  const interval = split(m.interval_seconds);
  const grace = split(m.grace_seconds);
  return { kind: m.kind, target: '', expectedStatus: m.expected_status ?? '200-399', failAfter: String(m.fail_after ?? 3), name: m.name, value: interval.value, unit: interval.unit, graceValue: m.grace_seconds === 0 ? '0' : grace.value, graceUnit: grace.unit, categoryId: m.category_id === null ? '' : String(m.category_id), notifyDown: m.notify_on_down, notifyUp: m.notify_on_up, notifyChannels: m.notify_channels, channelIds: m.channel_ids ?? [] };
}

function MonitorForm({ draft, setDraft, categories, errors, busy, submitLabel, editing, onSubmit, onCancel, onTest, testing, testResult }: {
  draft: Draft;
  setDraft: (d: Draft) => void;
  categories: CategoryRef[];
  errors: Record<string, string>;
  busy: boolean;
  submitLabel: string;
  editing: boolean;
  onSubmit: () => void;
  onCancel?: () => void;
  onTest?: () => void;
  testing?: boolean;
  testResult?: TestResult | null;
}) {
  const [channels, setChannels] = useState<NotificationChannelName[]>([]);
  const [channelError, setChannelError] = useState<string | null>(null);
  useEffect(() => {
    let cancelled = false;
    request<{ channels: NotificationChannelName[] }>('GET', '/api/notifications/channel-names').then((reply) => { if (!cancelled) setChannels(reply.channels); }).catch((caught) => { if (!cancelled) setChannelError(errorMessage(caught)); });
    return () => { cancelled = true; };
  }, []);
  const isActive = draft.kind !== 'heartbeat';
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
      <FormField label={t('Art')} error={errors.kind}>
        {(aria) => (
          <select {...aria} className="input" name="monitor-kind" value={draft.kind} disabled={editing} onChange={(e) => {
              const kind = e.target.value as MonitorKind;
              // Sinnvolle Vorgabe: aktive Prüfungen alle 5 Minuten, Lebenszeichen täglich (nur solange nichts Eigenes eingetragen ist).
              const untouched = draft.value === '1' && draft.unit === 'days';
              const activeUntouched = draft.value === '5' && draft.unit === 'minutes';
              if (kind !== 'heartbeat' && draft.kind === 'heartbeat' && untouched) setDraft({ ...draft, kind, value: '5', unit: 'minutes' });
              else if (kind === 'heartbeat' && draft.kind !== 'heartbeat' && activeUntouched) setDraft({ ...draft, kind, value: '1', unit: 'days' });
              else setDraft({ ...draft, kind });
            }}>
            {KINDS.map((k) => (
              <option key={k.value} value={k.value}>
                {t(k.label)}
              </option>
            ))}
          </select>
        )}
      </FormField>
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
      {isActive && (
        <FormField label={t('Ziel')} error={errors.target} hint={t(TARGET_HINT[draft.kind as 'http' | 'tcp' | 'ping'])}>
          {(aria) => (
            <input
              {...aria}
              className="input input--mono-plain"
              name="monitor-target"
              autoComplete="off"
              spellCheck={false}
              inputMode={draft.kind === 'http' ? 'url' : 'text'}
              placeholder={editing ? t('Leer lassen, um das gespeicherte Ziel zu behalten (Anzeige gespeicherter Ziele ist in den Einstellungen aus)') : TARGET_PLACEHOLDER[draft.kind as 'http' | 'tcp' | 'ping']}
              value={draft.target}
              onChange={(e) => setDraft({ ...draft, target: e.target.value })}
            />
          )}
        </FormField>
      )}
      <div className="grid-2">
        <FormField
          label={isActive ? t('Prüfen alle …') : t('Erwartet alle …')}
          error={errors.interval_seconds}
          hint={isActive ? t('Wie oft Meridian prüft (mindestens 1 Minute).') : t('Wie oft das Lebenszeichen spätestens kommen soll (mindestens 1 Minute).')}
        >
          {(aria) => (
            <div className="row">
              <input {...aria} className="input input--short" name="monitor-interval" inputMode="numeric" value={draft.value} onChange={(e) => setDraft({ ...draft, value: e.target.value })} />
              {unitSelect(draft.unit, (unit) => setDraft({ ...draft, unit }), t('Einheit des Intervalls'))}
            </div>
          )}
        </FormField>
        {!isActive && (
          <FormField label={t('Toleranz')} error={errors.grace_seconds} hint={t('Zusätzliche Wartezeit, bevor es als Ausfall gilt.')}>
            {(aria) => (
              <div className="row">
                <input {...aria} className="input input--short" name="monitor-grace" inputMode="numeric" value={draft.graceValue} onChange={(e) => setDraft({ ...draft, graceValue: e.target.value })} />
                {unitSelect(draft.graceUnit, (graceUnit) => setDraft({ ...draft, graceUnit }), t('Einheit der Toleranz'))}
              </div>
            )}
          </FormField>
        )}
        {isActive && (
          <FormField label={t('Ausfall nach … Fehlversuchen')} error={errors.fail_after} hint={t('Erst nach so vielen fehlgeschlagenen Prüfungen in Folge gilt das Ziel als ausgefallen und wird gemeldet (1 bis 10).')}>
            {(aria) => <input {...aria} className="input input--short" name="monitor-fail-after" inputMode="numeric" value={draft.failAfter} onChange={(e) => setDraft({ ...draft, failAfter: e.target.value })} />}
          </FormField>
        )}
      </div>
      {draft.kind === 'http' && (
        <FormField label={t('Erwartete Statuscodes')} error={errors.expected_status} hint={t('z. B. 200-299 oder 200,204,301-302')}>
          {(aria) => <input {...aria} className="input input--short input--mono-plain" name="monitor-expected" autoComplete="off" value={draft.expectedStatus} onChange={(e) => setDraft({ ...draft, expectedStatus: e.target.value })} />}
        </FormField>
      )}
      <label className="check">
        <input type="checkbox" checked={draft.notifyDown} onChange={(e) => setDraft({ ...draft, notifyDown: e.target.checked })} />
        <span>{isActive ? t('Benachrichtigen, wenn das Ziel ausfällt') : t('Benachrichtigen, wenn das Lebenszeichen ausbleibt')}</span>
      </label>
      <label className="check">
        <input type="checkbox" checked={draft.notifyUp} onChange={(e) => setDraft({ ...draft, notifyUp: e.target.checked })} />
        <span>{isActive ? t('Benachrichtigen, wenn es wieder erreichbar ist') : t('Benachrichtigen, wenn es sich wieder meldet')}</span>
      </label>
      <fieldset className="radios">
        <legend className="field__label">{t('Benachrichtigen über …')}</legend>
        {(['all', 'selected', 'none'] as const).map((mode) => (
          <label key={mode} className="check">
            <input type="radio" name="monitor-notify-channels" checked={draft.notifyChannels === mode} onChange={() => setDraft({ ...draft, notifyChannels: mode })} />
            <span>{mode === 'all' ? t('alle Kanäle') : mode === 'selected' ? t('nur ausgewählte Kanäle') : t('keine Kanäle')}</span>
          </label>
        ))}
      </fieldset>
      {errors.notify_channels !== undefined && <p className="field__error">{errors.notify_channels}</p>}
      {draft.notifyChannels === 'selected' && <div className="stack">
        {channelError !== null && <Alert tone="err">{channelError}</Alert>}
        {channelError === null && channels.length === 0 && <p className="hint">{t('Es gibt noch keinen Kanal. Ein Administrator legt sie unter Einstellungen → Benachrichtigungen an.')}</p>}
        {channels.map((channel) => <label key={channel.id} className="check">
          <input type="checkbox" checked={draft.channelIds.includes(channel.id)} onChange={(e) => setDraft({ ...draft, channelIds: e.target.checked ? [...draft.channelIds, channel.id] : draft.channelIds.filter((id) => id !== channel.id) })} />
          <span>{channel.name} ({channel.type}){!channel.enabled ? ' – ' + t('ausgeschaltet') : ''}</span>
        </label>)}
        {errors.channel_ids !== undefined && <p className="field__error">{errors.channel_ids}</p>}
      </div>}
      {testResult !== null && testResult !== undefined && <TestResultAlert result={testResult} />}
      <div className="row">
        <button type="submit" className="btn btn--solid" disabled={busy || draft.name.trim() === ''}>
          {submitLabel}
        </button>
        {isActive && onTest !== undefined && (
          <button type="button" className="btn btn--ghost" disabled={busy || testing === true} onClick={onTest}>
            {testing === true ? t('Prüft …') : t('Jetzt testen')}
          </button>
        )}
        {onCancel !== undefined && (
          <button type="button" className="btn btn--ghost" onClick={onCancel}>
            {t('Abbrechen')}</button>
        )}
      </div>
    </form>
  );
}

function payload(draft: Draft, editing: boolean): { body: Record<string, unknown> } | { error: Record<string, string> } {
  const interval = toSeconds(draft.value, draft.unit);
  const active = draft.kind !== 'heartbeat';
  const grace = active ? 0 : toSeconds(draft.graceValue, draft.graceUnit);
  const failAfter = /^\d{1,2}$/.test(draft.failAfter) ? Number.parseInt(draft.failAfter, 10) : null;
  const error: Record<string, string> = {};
  if (interval === null) error.interval_seconds = t('Zahl eingeben (ganze Zahl).');
  if (grace === null) error.grace_seconds = t('Zahl eingeben (ganze Zahl, 0 erlaubt).');
  if (active && failAfter === null) error.fail_after = t('Zahl eingeben (ganze Zahl).');
  if (active && !editing && draft.target.trim() === '') error.target = t('Pflichtfeld: das Ziel der Prüfung.');
  if (Object.keys(error).length > 0) return { error };
  const body: Record<string, unknown> = {
    name: draft.name.trim(),
    category_id: draft.categoryId === '' ? null : Number.parseInt(draft.categoryId, 10),
    interval_seconds: interval,
    notify_on_down: draft.notifyDown,
    notify_on_up: draft.notifyUp,
    notify_channels: draft.notifyChannels,
    channel_ids: draft.notifyChannels === 'selected' ? draft.channelIds : [],
  };
  if (!editing) body.kind = draft.kind;
  if (active) {
    body.fail_after = failAfter;
    if (draft.target.trim() !== '') body.target = draft.target.trim();
    if (draft.kind === 'http') body.expected_status = draft.expectedStatus.trim();
  } else {
    body.grace_seconds = grace;
  }
  return { body };
}

/** Überwachung: Lebenszeichen (Skripte melden sich per Ping, ADR 0011) und aktive Prüfungen von Web-Adresse, Port und Ping (ADR 0018). */
export function Monitors({ profile }: { profile: Profile }) {
  const csrf = profile.csrf_token;
  const [monitors, setMonitors] = useState<Monitor[] | null>(null);
  const [categories, setCategories] = useState<CategoryRef[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [draft, setDraft] = useState<Draft>(emptyDraft(''));
  const [editing, setEditing] = useState<{ id: number; draft: Draft; revealed?: boolean } | null>(null);
  const [issued, setIssued] = useState<Issued | null>(null);
  const [busy, setBusy] = useState(false);
  const [testing, setTesting] = useState<string | null>(null);
  const [testResult, setTestResult] = useState<{ source: 'form' | 'row'; label: string; ok: boolean; latency: number | null; note: string | null } | null>(null);
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
    const built = payload(d, method === 'PUT');
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

  /** Bearbeiten öffnen; bei aktiver Prüfung das gespeicherte Ziel laden, wenn der Admin „Gespeicherte Skripte und Links anzeigen“ eingeschaltet hat (nur im Zustand dieser Seite, nie im Browser-Speicher). */
  function startEdit(m: Monitor): void {
    setErrors({});
    setEditing({ id: m.id, draft: draftOf(m) });
    if (m.kind === 'heartbeat') {
      return;
    }
    request<{ target: string }>('GET', '/api/monitors/' + String(m.id) + '/source', { csrf })
      .then((source) => {
        setEditing((current) => (current !== null && current.id === m.id ? { ...current, draft: { ...current.draft, target: source.target }, revealed: true } : current));
      })
      .catch((caught: unknown) => {
        // „Aus“ ist der Normalfall (403 reveal_disabled): dann bleibt es beim leeren Feld; jeder andere Fehler wird gezeigt.
        if (!(caught instanceof ApiError && caught.revealDisabled)) {
          setFormError(errorMessage(caught));
        }
      });
  }

  /** Eine einzelne Prüfung jetzt (ohne Speichern, ohne Meldung); das Ergebnis erscheint über der Liste. */
  async function runTest(key: string, label: string, path: string, body: Record<string, unknown>): Promise<void> {
    setTesting(key);
    setTestResult(null);
    setFormError(null);
    try {
      const reply = await request<{ ok: boolean; latency_ms: number | null; note: string | null }>('POST', path, { csrf, body });
      setTestResult({ source: key === 'form' ? 'form' : 'row', label, ok: reply.ok, latency: reply.latency_ms, note: reply.note });
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 422) {
        setErrors(caught.fields);
        setFormError(t('Bitte die markierten Felder korrigieren.'));
      } else {
        setFormError(errorMessage(caught));
      }
    } finally {
      setTesting(null);
    }
  }

  function testBody(d: Draft): Record<string, unknown> {
    const body: Record<string, unknown> = {};
    if (d.target.trim() !== '') body.target = d.target.trim();
    if (d.kind === 'http') body.expected_status = d.expectedStatus.trim();
    return body;
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
        <h2>{t('Überwachung')}</h2>
        <p className="card__lead">
          {t('Meridian prüft eine Web-Adresse, einen Netzwerk-Port oder einen Host per Ping, oder wartet auf das Lebenszeichen eines Skripts, einer Sicherung oder eines Routers. Bleibt die Rückmeldung aus, meldet Meridian den Ausfall und danach die Rückkehr.')}</p>
        {loadError !== null && <Alert tone="err">{loadError}</Alert>}
        {formError !== null && <Alert tone="err">{formError}</Alert>}
        {issued !== null && <IssuedToken issued={issued} onClose={() => setIssued(null)} />}
        {testResult !== null && testResult.source === 'row' && <TestResultAlert result={testResult} />}
        {monitors !== null && monitors.length === 0 && <p className="card__lead">{t('Noch keine Überwachung angelegt.')}</p>}
        {monitors !== null && monitors.length > 0 && (
          <div className="table-wrap">
            <table className="table table--stack">
              <caption className="visually-hidden">{t('Überwachungen')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('Name')}</th>
                  <th scope="col">{t('Art')}</th>
                  <th scope="col">{t('Zustand')}</th>
                  <th scope="col">{t('Intervall')}</th>
                  <th scope="col">{t('Letzte Rückmeldung')}</th>
                  <th scope="col">{t('Aktion')}</th>
                </tr>
              </thead>
              <tbody>
                {monitors.map((m) => {
                  const active = m.kind !== 'heartbeat';
                  const state = (active ? ACTIVE_STATE : STATE)[m.state];
                  return (
                    <tr key={m.id}>
                      <td data-label={t('Name')}>
                        {m.name}
                        {m.category !== null && <div className="hint">{m.category}</div>}
                        {active && m.target !== null && <div className="mono">{m.target}</div>}
                      </td>
                      <td data-label={t('Art')}>{t(KIND_SHORT[m.kind])}</td>
                      <td data-label={t('Zustand')}>
                        <span className="runstate">
                          <span className={'dot dot--' + state.dot} aria-hidden="true" />
                          {t(state.label)}
                        </span>
                        {active && m.last_check_ok === false && m.last_check_note !== null && <div className="hint">{m.last_check_note}</div>}
                      </td>
                      <td data-label={t('Intervall')}>{describe(m.interval_seconds)}</td>
                      <td data-label={t('Letzte Rückmeldung')}>
                        {active ? (
                          m.last_check_at === null ? (
                            <span className="muted">{t('noch nie')}</span>
                          ) : (
                            <>
                              {formatTime(m.last_check_at)}
                              {m.last_latency_ms !== null && <div className="hint">{m.last_latency_ms} ms</div>}
                            </>
                          )
                        ) : m.last_ping_at === null ? (
                          <span className="muted">{t('noch nie')}</span>
                        ) : (
                          formatTime(m.last_ping_at)
                        )}
                      </td>
                      <td data-label={t('Aktion')}>
                        {m.can_edit ? (
                          <div className="row">
                            <button type="button" className="btn btn--ghost btn--small" aria-label={t('{name} bearbeiten', { name: m.name })} onClick={() => startEdit(m)}>
                              {t('Bearbeiten')}</button>
                            {active && (
                              <button type="button" className="btn btn--ghost btn--small" disabled={busy || testing !== null} aria-label={t('{name} testen', { name: m.name })} onClick={() => void runTest('row' + String(m.id), m.name, '/api/monitors/' + String(m.id) + '/test', {})}>
                                {testing === 'row' + String(m.id) ? t('Prüft …') : t('Testen')}</button>
                            )}
                            {!active && (
                              <Confirm label={t('Neue Adresse')} question={t('Neue Ping-Adresse erzeugen? Die alte gilt sofort nicht mehr.')} confirmLabel={t('Ja, erneuern')} busy={busy} accessibleName={t('Ping-Adresse von {name} erneuern', { name: m.name })} onConfirm={() => void act(async () => {
                                const reply = await request<{ ping_token: string }>('POST', '/api/monitors/' + String(m.id) + '/token', { csrf });
                                setIssued({ name: m.name, token: reply.ping_token });
                              })} />
                            )}
                            <Confirm label={t('Löschen')} question={t('Überwachung löschen?')} confirmLabel={t('Ja, löschen')} busy={busy} accessibleName={t('{name} löschen', { name: m.name })} onConfirm={() => void act(async () => {
                              await request('DELETE', '/api/monitors/' + String(m.id), { csrf });
                            })} />
                          </div>
                        ) : (
                          <span className="muted">{t('nur lesen')}</span>
                        )}
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
            <h3>{t('Überwachung bearbeiten')}</h3>
            {editing.revealed === true && <Alert tone="warn">{t('Das gespeicherte Ziel wird angezeigt (Einstellung des Admins). Der Abruf wurde protokolliert. Beim Speichern wird es so übernommen, wie es hier steht.')}</Alert>}
            <MonitorForm
              draft={editing.draft}
              setDraft={(d) => setEditing({ ...editing, draft: d })}
              categories={categories}
              errors={errors}
              busy={busy}
              submitLabel={t('Speichern')}
              editing
              testing={testing === 'form'}
            testResult={testResult !== null && testResult.source === 'form' ? testResult : null}
              onTest={() => void runTest('form', editing.draft.name, '/api/monitors/' + String(editing.id) + '/test', testBody(editing.draft))}
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
            submitLabel={t('Anlegen')}
            editing={false}
            testing={testing === 'form'}
            testResult={testResult !== null && testResult.source === 'form' ? testResult : null}
            onTest={() => void runTest('form', draft.name === '' ? t('Testprüfung') : draft.name, '/api/monitors/test', { kind: draft.kind, category_id: draft.categoryId === '' ? null : Number.parseInt(draft.categoryId, 10), ...testBody(draft) })}
            onSubmit={() => void submit('POST', '/api/monitors', draft).then((ok) => ok && setDraft(emptyDraft(draft.categoryId)))}
          />
        </section>
      )}
    </>
  );
}
