/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import { Alert } from '../components/Alert';
import { ScheduleBuilder } from '../components/ScheduleBuilder';
import { Confirm } from '../components/Confirm';
import { FormField } from '../components/FormField';
import { EMPTY_SCRIPT, ShellSections, shellFormFromJob, splitTarget, targetKey, useShellTargets } from '../components/ShellSections';
import type { ScriptDraft, ShellForm } from '../components/ShellSections';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { canEditHttp, canEditShell } from '../lib/permissions';
import { setPendingRun } from '../lib/pending';
import { triggerRun } from '../lib/runs';
import { hrefOf, navigate } from '../lib/useHashRoute';
import { basicAuthValue, parseCurl } from '../lib/curl';
import { t } from '../i18n';
import type { CategoryRef, HttpMethod, JobDetail, JobLimits, JobSource, JobType, NotificationChannelName, OverlapPolicy, Profile, StoreResponse } from '../types';

const METHODS: HttpMethod[] = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];
const WITH_BODY: HttpMethod[] = ['POST', 'PUT', 'PATCH', 'DELETE'];
const LONG_TIMEOUT = 60;
const SHELL_DEFAULT_TIMEOUT = 300;

type TabId = 'general' | 'advanced' | 'notify';

interface HeaderRow {
  key: number;
  name: string;
  value: string;
}

interface Form {
  name: string;
  isEnabled: boolean;
  categoryId: string;
  method: HttpMethod;
  cron: string;
  timezone: string;
  timeout: string;
  expected: string;
  redirects: string;
  retryCount: string;
  retryDelay: string;
  overlap: OverlapPolicy;
  catchUp: boolean;
  /** „Zeitplan läuft ab“: Schalter und Ortszeit des Jobs (`2026-12-31T23:59`, Eingabefeld datetime-local). */
  expiresEnabled: boolean;
  expiresLocal: string;
  /** Benachrichtigungen (ADR 0007 N4). */
  notifyFailure: boolean;
  notifyAfter: string;
  disableEnabled: boolean;
  disableAfter: string;
  notifyDisable: boolean;
  tlsEnabled: boolean;
  tlsDays: string;
  runtimeEnabled: boolean;
  runtimePercent: string;
  notifyRecovery: boolean;
  notifyChannels: 'all' | 'selected' | 'none';
  notifyIds: number[];
  notifyRecipients: string;
  store: StoreResponse;
}

/** UTC-Zeitpunkt als Ortszeit in `timezone` für `<input type="datetime-local">` (`2026-12-31T23:59`); leer ohne Wert. */
function localDateTime(iso: string | null, timezone: string): string {
  if (iso === null) {
    return '';
  }
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return '';
  }
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date);
  const get = (type: string): string => parts.find((part) => part.type === type)?.value ?? '00';
  return get('year') + '-' + get('month') + '-' + get('day') + 'T' + get('hour') + ':' + get('minute');
}

/**
 * Geheimnisfelder: nach dem Absenden geleert. Vorbefüllt nur, wenn der Admin „Gespeicherte Skripte und Links im Editor
 * anzeigen“ eingeschaltet hat (`GET /api/jobs/{id}/source`); auch dann nur im Zustand dieser Seite, nie in Browser-Speicher
 * oder URL, und beim Speichern und Verlassen gelöscht.
 */
interface RequestDraft {
  url: string;
  headers: HeaderRow[];
  body: string;
}

const EMPTY_REQUEST: RequestDraft = { url: '', headers: [], body: '' };

function timezones(current: string): string[] {
  let list: string[] = [];
  try {
    list = Intl.supportedValuesOf('timeZone');
  } catch {
    list = ['Europe/Berlin'];
  }
  const set = new Set(list);
  set.add('UTC');
  set.add(current);
  return [...set].sort((a, b) => a.localeCompare(b));
}

function formFromJob(job: JobDetail): Form {
  const http = job.http;
  return {
    name: job.name,
    isEnabled: job.is_enabled,
    categoryId: job.category === null ? '' : String(job.category.id),
    method: http?.method ?? 'GET',
    cron: job.cron,
    timezone: job.timezone,
    timeout: String(job.type === 'shell' ? (job.shell?.timeout_seconds ?? SHELL_DEFAULT_TIMEOUT) : (http?.timeout_seconds ?? 30)),
    expected: http?.expected_status ?? '200-299',
    redirects: String(http?.max_redirects ?? 3),
    retryCount: String(job.retry_count),
    retryDelay: String(job.retry_delay_seconds),
    overlap: job.overlap_policy,
    catchUp: job.catch_up,
    expiresEnabled: job.expires_at !== null,
    expiresLocal: localDateTime(job.expires_at, job.timezone),
    notifyFailure: job.notify.on_failure,
    notifyAfter: String(job.notify.failure_after),
    disableEnabled: job.notify.disable_after !== null,
    disableAfter: String(job.notify.disable_after ?? 5),
    notifyDisable: job.notify.on_disable,
    tlsEnabled: job.notify.tls_warn_days !== null,
    tlsDays: String(job.notify.tls_warn_days ?? 14),
    runtimeEnabled: job.notify.runtime_warn_percent != null,
    runtimePercent: String(job.notify.runtime_warn_percent ?? 200),
    notifyRecovery: job.notify.on_recovery,
    notifyChannels: job.notify.channels,
    notifyIds: job.notify.channel_ids,
    notifyRecipients: (job.notify.recipients ?? []).join('\n'),
    store: http?.store_response ?? 'inherit',
  };
}

function initialForm(limits: JobLimits | null, categories: CategoryRef[]): Form {
  return {
    name: '',
    isEnabled: true,
    categoryId: categories[0] === undefined ? '' : String(categories[0].id),
    method: 'GET',
    cron: '*/5 * * * *',
    timezone: 'Europe/Berlin',
    timeout: String(Math.min(30, limits?.max_timeout_seconds ?? 30)),
    expected: '200-299',
    redirects: '3',
    retryCount: '0',
    retryDelay: '60',
    overlap: 'skip',
    catchUp: false,
    expiresEnabled: false,
    expiresLocal: '',
    notifyFailure: false,
    notifyAfter: '1',
    disableEnabled: false,
    disableAfter: '5',
    notifyDisable: true,
    tlsEnabled: false,
    tlsDays: '14',
    runtimeEnabled: false,
    runtimePercent: '200',
    notifyRecovery: false,
    notifyChannels: 'all',
    notifyIds: [],
    notifyRecipients: '',
    store: 'inherit',
  };
}

function toInt(text: string): number | null {
  return /^[0-9]{1,6}$/.test(text.trim()) ? Number.parseInt(text, 10) : null;
}

/** Vorschau der nächsten Termine, 400 ms nach der letzten Eingabe. */
function useSchedulePreview(cron: string, timezone: string): { runs: string[] | null; error: string | null } {
  const [state, setState] = useState<{ runs: string[] | null; error: string | null }>({ runs: null, error: null });
  useEffect(() => {
    if (cron.trim() === '') {
      setState({ runs: null, error: null });
      return undefined;
    }
    let cancelled = false;
    const timer = window.setTimeout(() => {
      const query = new URLSearchParams({ cron: cron.trim(), timezone, count: '5' });
      request<{ runs: string[] }>('GET', '/api/schedule/preview?' + query.toString())
        .then((data) => {
          if (!cancelled) {
            setState({ runs: data.runs, error: null });
          }
        })
        .catch((caught: unknown) => {
          if (!cancelled) {
            setState({ runs: null, error: caught instanceof ApiError && caught.fields.cron !== undefined ? caught.fields.cron : caught instanceof ApiError && caught.fields.timezone !== undefined ? caught.fields.timezone : errorMessage(caught) });
          }
        });
    }, 400);
    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [cron, timezone]);
  return state;
}

function SchedulePreview({ cron, timezone }: { cron: string; timezone: string }) {
  const { runs, error } = useSchedulePreview(cron, timezone);
  const format = useMemo(() => {
    try {
      // Feste Sekunden-Intervalle brauchen die Sekunden in der Vorschau.
      const seconds = cron.trim().startsWith('@every') ? { second: '2-digit' as const } : {};
      return new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', ...seconds, timeZone: timezone });
    } catch {
      return null;
    }
  }, [timezone, cron]);
  return (
    <div className="preview" aria-live="polite">
      <div className="preview__title">{t('NÄCHSTE LÄUFE')} · {timezone.toUpperCase()}</div>
      {error !== null && <div className="preview__error">{error}</div>}
      {error === null && runs === null && <div className="preview__row">{t('Wird berechnet …')}</div>}
      {error === null &&
        runs?.map((iso) => (
          <div key={iso} className="preview__row">
            {format === null ? iso : format.format(new Date(iso))}
          </div>
        ))}
    </div>
  );
}

export function JobEdit({ profile, id, onEditorModeChange }: { profile: Profile; id: string | null; onEditorModeChange: (mode: 'simple' | 'expert') => void }) {
  const editing = id !== null;
  const [job, setJob] = useState<JobDetail | null>(null);
  const [categories, setCategories] = useState<CategoryRef[] | null>(null);
  const [limits, setLimits] = useState<JobLimits | null>(null);
  const [form, setForm] = useState<Form | null>(null);
  const [draft, setDraft] = useState<RequestDraft>(EMPTY_REQUEST);
  const [replacing, setReplacing] = useState(!editing);
  const [type, setType] = useState<JobType>('http');
  const [shell, setShell] = useState<ShellForm>(shellFormFromJob(null));
  const [script, setScript] = useState<ScriptDraft>(EMPTY_SCRIPT);
  const [replacingScript, setReplacingScript] = useState(!editing);
  const envKey = useRef(1);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [nextKey, setNextKey] = useState(1);
  /** true: gespeicherte Werte wurden geladen und stehen in den Feldern. */
  const [revealed, setRevealed] = useState(false);
  const [tabState, setTab] = useState<TabId>('general');
  // Einfach zeigt nur „Allgemein“; Experte alle Reiter. Die Werte der verborgenen Reiter bleiben im Formular erhalten.
  const [mode, setModeState] = useState<'simple' | 'expert'>(profile.job_editor_mode ?? 'simple');
  const [modeSaving, setModeSaving] = useState(false);
  const tab: TabId = mode === 'simple' ? 'general' : tabState;
  async function setMode(next: 'simple' | 'expert'): Promise<void> {
    setModeSaving(true);
    try {
      await request('PUT', '/api/auth/job-editor-mode', { csrf: profile.csrf_token, body: { job_editor_mode: next } });
      setModeState(next);
      onEditorModeChange(next);
    } catch (error) {
      setFormError(errorMessage(error));
    } finally {
      setModeSaving(false);
    }
  }
  const [channelNames, setChannelNames] = useState<NotificationChannelName[]>([]);

  // Auswahl der Kanäle für „nur ausgewählte“: nur Namen und Art, nie Zugangsdaten.
  useEffect(() => {
    let cancelled = false;
    request<{ channels: NotificationChannelName[] }>('GET', '/api/notifications/channel-names')
      .then((data) => {
        if (!cancelled) {
          setChannelNames(data.channels);
        }
      })
      .catch(() => undefined);
    return () => {
      cancelled = true;
    };
  }, []);
  const [auth, setAuth] = useState<{ mode: 'basic' | 'bearer'; user: string; secret: string }>({ mode: 'basic', user: '', secret: '' });
  const [curlText, setCurlText] = useState('');
  const [curlNote, setCurlNote] = useState<{ tone: 'ok' | 'err'; text: string } | null>(null);
  const [sourceNote, setSourceNote] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    async function load(): Promise<void> {
      try {
        const [lim, existing] = await Promise.all([
          request<JobLimits>('GET', '/api/jobs/limits'),
          id === null ? Promise.resolve(null) : request<{ job: JobDetail }>('GET', '/api/jobs/' + id),
        ]);
        if (cancelled) {
          return;
        }
        setLimits(lim);
        setJob(existing?.job ?? null);
        setType(existing?.job.type ?? 'http');
        setShell(shellFormFromJob(existing?.job.shell));
        setForm(existing === null ? initialForm(lim, []) : formFromJob(existing.job));
      } catch (caught) {
        if (!cancelled) {
          setLoadError(errorMessage(caught));
        }
      }
    }
    void load();
    return () => {
      cancelled = true;
    };
  }, [id]);

  // Gespeicherte Werte vorbefüllen (Einstellung jobs.reveal_for_edit). Bei „aus“ antwortet der Server 403 mit
  // reveal_disabled: dann bleibt alles wie bisher (nur „ersetzen“). Jeder Abruf wird auf dem Server protokolliert.
  const sourceAllowed = job !== null && job.can.edit && (job.type !== 'shell' || canEditShell(profile));
  useEffect(() => {
    if (id === null || !sourceAllowed) {
      return undefined;
    }
    let cancelled = false;
    request<JobSource>('GET', '/api/jobs/' + id + '/source', { csrf: profile.csrf_token })
      .then((source) => {
        if (cancelled) {
          return;
        }
        if (source.type === 'http') {
          let key = 1;
          setDraft({ url: source.url, headers: source.headers.map((h) => ({ key: key++, name: h.name, value: h.value })), body: source.body ?? '' });
          setNextKey(key);
          setReplacing(true);
        } else {
          setScript({ source: source.script, env: source.env.map((e) => ({ key: envKey.current++, name: e.name, value: e.value })) });
          setReplacingScript(true);
        }
        setRevealed(true);
      })
      .catch((caught: unknown) => {
        if (!cancelled && !(caught instanceof ApiError && caught.revealDisabled)) {
          setSourceNote(t('Gespeicherte Werte konnten nicht geladen werden:') + ' ' + errorMessage(caught));
        }
      });
    return () => {
      cancelled = true;
      // Beim Verlassen der Seite nichts zurücklassen.
      setDraft(EMPTY_REQUEST);
      setScript(EMPTY_SCRIPT);
      setRevealed(false);
    };
  }, [id, sourceAllowed]);

  // Kategorien für die Auswahl: Shell-Jobs gibt es nur uneingeschränkt, dort zählt die Sicht auf alle Kategorien.
  useEffect(() => {
    let cancelled = false;
    request<{ categories: CategoryRef[] }>('GET', '/api/categories?permission=' + (type === 'shell' ? 'jobs.view' : 'jobs.edit_http'))
      .then((cats) => {
        if (cancelled) {
          return;
        }
        setCategories(cats.categories);
        if (id === null) {
          setForm((current) => {
            if (current === null || cats.categories.some((c) => String(c.id) === current.categoryId)) {
              return current;
            }
            return { ...current, categoryId: cats.categories[0] === undefined ? '' : String(cats.categories[0].id) };
          });
        }
      })
      .catch((caught: unknown) => {
        if (!cancelled) {
          setLoadError(errorMessage(caught));
        }
      });
    return () => {
      cancelled = true;
    };
  }, [type, id]);

  const shellActive = type === 'shell';
  const shellTargets = useShellTargets(shellActive && form !== null, form?.categoryId ?? '');
  const shellTargetCount = shellTargets.targets?.length ?? 0;
  // Neuer Shell-Job: ersten freigegebenen Ort vorwählen, sobald die Liste da ist.
  useEffect(() => {
    if (shellActive && shellTargets.targets !== null && shellTargetCount > 0 && shell.target === '' && !editing) {
      const first = shellTargets.targets[0];
      if (first !== undefined) {
        setShell((current) => ({ ...current, target: targetKey(first.kind, first.name), user: '' }));
      }
    }
  }, [shellActive, shellTargets.targets, shellTargetCount, shell.target, editing]);

  const set = useCallback(<K extends keyof Form>(key: K, value: Form[K]): void => {
    setForm((current) => (current === null ? current : { ...current, [key]: value }));
  }, []);

  if (loadError !== null) {
    return (
      <>
        <Alert tone="err">{loadError}</Alert>
        <p>
          <a href={hrefOf('jobs')}>{t('Zurück zur Jobliste')}</a>
        </p>
      </>
    );
  }
  if (form === null || categories === null || limits === null || (editing && job === null)) {
    return <div className="card__lead">{t('Lädt …')}</div>;
  }
  if (job !== null && !job.can.edit) {
    return (
      <>
        <Alert tone="warn">{t('Du darfst diesen Job nicht ändern.')}</Alert>
        <p>
          <a href={hrefOf('jobs/' + String(job.id))}>{t('Zurück zum Job')}</a>
        </p>
      </>
    );
  }
  if (!editing && !canEditHttp(profile) && !canEditShell(profile)) {
    return <Alert tone="warn">{t('Du darfst keine Jobs anlegen.')}</Alert>;
  }
  if (editing && job !== null && job.type === 'shell' && !canEditShell(profile)) {
    return (
      <>
        <Alert tone="warn">{t('Shell-Jobs dürfen nur Benutzer mit dem Recht „Shell-Jobs bearbeiten“ für alle Kategorien ändern.')}</Alert>
        <p>
          <a href={hrefOf('jobs/' + String(job.id))}>{t('Zurück zum Job')}</a>
        </p>
      </>
    );
  }

  const maxTimeout = shellActive ? (limits.shell_max_timeout_seconds ?? 3600) : limits.max_timeout_seconds;
  const timeoutNumber = toInt(form.timeout);
  const bodyAllowed = WITH_BODY.includes(form.method);
  const unrestricted = shellActive ? canEditShell(profile) : canEditHttp(profile, null);
  const storeNever = limits.response_storage === 'never';
  const inheritLabel = t('Wie global ({state})', { state: limits.response_storage === 'on' ? t('an') : t('aus') });

  const shownKeys = new Set([
    'name', 'category_id', 'cron', 'timezone', 'expires_at', 'notify', 'notify.failure_after', 'notify.disable_after', 'notify.tls_warn_days', 'notify.runtime_warn_percent', 'notify.channels', 'notify.channel_ids', 'notify.recipients', 'overlap_policy', 'retry_count', 'retry_delay_seconds', 'http.method',
    'http.timeout_seconds', 'http.expected_status', 'http.max_redirects', 'http.store_response', 'request.url', 'request.body',
    'shell.target', 'shell.interpreter', 'shell.user', 'shell.workdir', 'shell.timeout_seconds', 'script.source',
  ]);
  const headerErrors = Object.entries(errors).filter(([key]) => key.startsWith('request.headers'));
  const otherErrors = Object.entries(errors).filter(([key]) => !shownKeys.has(key) && !key.startsWith('request.headers') && !key.startsWith('script.env') && !(key === 'script' && editing && !replacingScript) && !(key === 'request' && editing && !replacing));


  const tabOf = (key: string): TabId => {
    if (key.startsWith('http.timeout') || key.startsWith('shell.timeout') || key === 'http.expected_status' || key === 'http.max_redirects' || key === 'http.store_response' || key === 'overlap_policy' || key.startsWith('retry_') || key.startsWith('request.headers') || key === 'request.body' || key.startsWith('script.env')) {
      return 'advanced';
    }
    if (key.startsWith('notify')) {
      return 'notify';
    }
    return 'general';
  };
  const errorTabs = new Set<TabId>(Object.keys(errors).map(tabOf));
  const tabLabels: ReadonlyArray<{ id: TabId; label: string }> = [
    { id: 'general', label: t('Allgemein') },
    { id: 'advanced', label: 'Erweitert' },
    { id: 'notify', label: t('Benachrichtigungen') },
  ];
  function onTabKey(event: KeyboardEvent<HTMLButtonElement>, current: TabId): void {
    const usable = tabLabels.map((tb) => tb.id);
    const i = usable.indexOf(current);
    let next: TabId | null = null;
    if (event.key === 'ArrowRight') next = usable[(i + 1) % usable.length] ?? null;
    else if (event.key === 'ArrowLeft') next = usable[(i - 1 + usable.length) % usable.length] ?? null;
    else if (event.key === 'Home') next = usable[0] ?? null;
    else if (event.key === 'End') next = usable[usable.length - 1] ?? null;
    if (next !== null) {
      event.preventDefault();
      setTab(next);
      document.getElementById('jobtab-' + next)?.focus();
    }
  }

  function importCurl(): void {
    const parsed = parseCurl(curlText);
    if (parsed === null) {
      setCurlNote({ tone: 'err', text: t('Das ist kein erkennbarer curl-Befehl. Er muss mit „curl“ beginnen und eine URL enthalten.') });
      return;
    }
    set('method', parsed.method);
    let key = nextKey;
    setDraft({ url: parsed.url, headers: parsed.headers.map((h) => ({ key: key++, name: h.name, value: h.value })), body: parsed.body ?? '' });
    setNextKey(key);
    setCurlText('');
    const note = t('Übernommen: {method}, {n} Header{body}. Bitte prüfen und speichern.', { method: parsed.method, n: parsed.headers.length, body: parsed.body !== null ? ', Body' : '' });
    setCurlNote({ tone: 'ok', text: parsed.ignored.length > 0 ? note + ' ' + t('Nicht übernommen:') + ' ' + parsed.ignored.join(', ') + '.' : note });
  }

  /** Setzt aus den Zugangsdaten den Header `Authorization` (ersetzt einen vorhandenen) und leert die Felder. */
  function applyAuth(): void {
    const value = auth.mode === 'basic' ? basicAuthValue(auth.user + ':' + auth.secret) : 'Bearer ' + auth.secret.trim();
    let key = nextKey;
    setDraft((current) => {
      const others = current.headers.filter((h) => h.name.trim().toLowerCase() !== 'authorization');
      return { ...current, headers: [...others, { key: key++, name: 'Authorization', value }] };
    });
    setNextKey(key);
    setAuth({ mode: auth.mode, user: '', secret: '' });
  }

  function addHeader(): void {
    setDraft((current) => ({ ...current, headers: [...current.headers, { key: nextKey, name: '', value: '' }] }));
    setNextKey((n) => n + 1);
  }

  function startReplacing(): void {
    setDraft(EMPTY_REQUEST);
    setReplacing(true);
  }

  function cancelReplacing(): void {
    setDraft(EMPTY_REQUEST);
    setReplacing(false);
    setRevealed(false);
    setErrors((current) => Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith('request'))));
  }

  /** Springt zum Reiter des ersten Fehlers (Allgemein hat Vorrang) und setzt den Fokus auf das erste fehlerhafte Feld. */
  function jumpToError(keys: string[]): void {
    if (keys.length === 0) {
      return;
    }
    const target: TabId = keys.some((k) => tabOf(k) === 'general') ? 'general' : keys.some((k) => tabOf(k) === 'advanced') ? 'advanced' : 'notify';
    if (target !== 'general') {
      setModeState('expert');
    }
    setTab(target);
    window.setTimeout(() => {
      document.querySelector<HTMLElement>('#jobpanel-' + target + ' [aria-invalid="true"]')?.focus();
    }, 0);
  }

  async function submit(afterwards: 'show' | 'test'): Promise<void> {
    if (form === null) {
      return;
    }
    const local: Record<string, string> = {};
    const timeout = toInt(form.timeout);
    const redirects = toInt(form.redirects);
    const retryCount = toInt(form.retryCount);
    const retryDelay = toInt(form.retryDelay);
    if (timeout === null) {
      local[shellActive ? 'shell.timeout_seconds' : 'http.timeout_seconds'] = 'Zeitlimit: ganze Zahl in Sekunden.';
    }
    if (!shellActive && redirects === null) {
      local['http.max_redirects'] = t('Weiterleitungen: ganze Zahl von 0 bis 5.');
    }
    if (retryCount === null) {
      local.retry_count = t('Wiederholungen: ganze Zahl von 0 bis 10.');
    }
    if (retryDelay === null) {
      local.retry_delay_seconds = 'Abstand: ganze Zahl in Sekunden.';
    }
    if (!shellActive && replacing && draft.url.trim() === '') {
      local['request.url'] = t('Pflichtfeld: bitte die URL angeben.');
    }
    const picked = splitTarget(shell.target);
    if (shellActive && picked === null) {
      local['shell.target'] = t('Bitte einen Ausführungsort wählen.');
    }
    if (shellActive && replacingScript && script.source.trim() === '') {
      local['script.source'] = t('Pflichtfeld: bitte das Skript eingeben.');
    }
    if (Object.keys(local).length > 0 || timeout === null || (!shellActive && redirects === null) || retryCount === null || retryDelay === null) {
      setErrors(local);
      setFormError(t('Bitte die markierten Felder korrigieren.'));
      jumpToError(Object.keys(local));
      return;
    }

    const body: Record<string, unknown> = {
      type,
      name: form.name,
      category_id: form.categoryId === '' ? null : Number.parseInt(form.categoryId, 10),
      cron: form.cron.trim(),
      timezone: form.timezone,
      is_enabled: form.isEnabled,
      catch_up: form.catchUp,
      expires_at: form.expiresEnabled ? form.expiresLocal : null,
      notify: {
        on_failure: form.notifyFailure,
        failure_after: /^\d+$/.test(form.notifyAfter) ? Number.parseInt(form.notifyAfter, 10) : form.notifyAfter,
        on_recovery: form.notifyRecovery,
        channels: form.notifyChannels,
        channel_ids: form.notifyChannels === 'selected' ? form.notifyIds : [],
        recipients: form.notifyRecipients.split(/\r?\n/).map((address) => address.trim()).filter(Boolean),
        disable_after: form.disableEnabled ? (/^\d+$/.test(form.disableAfter) ? Number.parseInt(form.disableAfter, 10) : form.disableAfter) : null,
        on_disable: form.notifyDisable,
        runtime_warn_percent: form.runtimeEnabled ? (/^\d+$/.test(form.runtimePercent) ? Number.parseInt(form.runtimePercent, 10) : form.runtimePercent) : null,
        tls_warn_days: form.tlsEnabled && !shellActive ? (/^\d+$/.test(form.tlsDays) ? Number.parseInt(form.tlsDays, 10) : form.tlsDays) : null,
      },
      overlap_policy: form.overlap,
      retry_count: retryCount,
      retry_delay_seconds: retryDelay,
    };
    if (shellActive && picked !== null) {
      body.shell = {
        target: picked,
        interpreter: shell.interpreter,
        user: picked.kind === 'docker' && shell.user !== '' ? shell.user : null,
        workdir: picked.kind === 'docker' && shell.workdir.trim() !== '' ? shell.workdir.trim() : null,
        timeout_seconds: timeout,
      };
      if (replacingScript) {
        body.script = { source: script.source, env: script.env.filter((r) => r.name.trim() !== '' || r.value !== '').map((r) => ({ name: r.name.trim(), value: r.value })) };
      }
    } else {
      body.http = { method: form.method, timeout_seconds: timeout, expected_status: form.expected.trim(), max_redirects: redirects ?? 3, store_response: form.store };
    }
    if (!shellActive && replacing) {
      // URL, Header und Body gehen nur gemeinsam; fehlende Header = keine, fehlender Body = keiner.
      body.request = {
        url: draft.url.trim(),
        headers: draft.headers.filter((h) => h.name.trim() !== '' || h.value !== '').map((h) => ({ name: h.name.trim(), value: h.value })),
        body: bodyAllowed && draft.body !== '' ? draft.body : null,
      };
    }

    setBusy(true);
    setErrors({});
    setFormError(null);
    try {
      const data = editing
        ? await request<{ job: JobDetail }>('PUT', '/api/jobs/' + String(id), { csrf: profile.csrf_token, body })
        : await request<{ job: JobDetail }>('POST', '/api/jobs', { csrf: profile.csrf_token, body });
      // Geheimnisse sofort aus dem Zustand löschen, bevor irgendetwas anderes geschieht.
      setDraft(EMPTY_REQUEST);
      setReplacing(false);
      setScript(EMPTY_SCRIPT);
      setReplacingScript(false);
      setRevealed(false);
      const jobId = String(data.job.id);
      if (afterwards === 'test') {
        try {
          setPendingRun(jobId, await triggerRun('test', jobId, profile.csrf_token), 'test');
        } catch {
          // Gespeichert ist gespeichert; der Testlauf lässt sich in der Job-Ansicht erneut starten.
        }
      }
      navigate('jobs/' + jobId);
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 422) {
        setErrors(caught.fields);
        jumpToError(Object.keys(caught.fields));
        setFormError(caught.message);
      } else {
        setFormError(errorMessage(caught));
      }
      // Die Geheimnisfelder bleiben nach einem Fehler erhalten, damit nichts neu getippt werden muss; sie verlassen die Seite nie.
    } finally {
      setBusy(false);
    }
  }

  async function remove(): Promise<void> {
    setBusy(true);
    try {
      await request('DELETE', '/api/jobs/' + String(id), { csrf: profile.csrf_token });
      navigate('jobs');
    } catch (caught) {
      setFormError(errorMessage(caught));
      setBusy(false);
    }
  }

  return (
    <form
      className="form editor"
      onSubmit={(event: FormEvent) => {
        event.preventDefault();
        void submit('show');
      }}
      noValidate
      autoComplete="off"
    >
      {formError !== null && <Alert tone="err">{formError}</Alert>}
      {revealed && (
        <Alert tone="warn">
          <span data-testid="reveal-note">{t('Gespeicherte Werte werden angezeigt (Einstellung des Admins).')}</span> {t('Der Abruf wurde protokolliert. Beim Speichern werden {what} so übernommen, wie sie hier stehen.', { what: shellActive ? t('Skript und Umgebungsvariablen') : t('URL, Header und Body') })}
        </Alert>
      )}
      {sourceNote !== null && <Alert tone="info">{sourceNote}</Alert>}
      {otherErrors.length > 0 && (
        <Alert tone="err">
          <ul className="errlist">
            {otherErrors.map(([key, message]) => (
              <li key={key}>{message}</li>
            ))}
          </ul>
        </Alert>
      )}

      <div className="modebar">
        <div className="modebar__text">{mode === 'simple' ? t('Einfach: die wichtigsten Angaben. Weitere Optionen findest du im Experten-Modus.') : t('Experte: alle Optionen in Reitern. Nichts geht beim Umschalten verloren.')}</div>
        <div className="modebar__buttons" role="group" aria-label={t('Ansicht des Editors')}>
          <button type="button" className="modebar__btn" aria-pressed={mode === 'simple'} disabled={modeSaving} onClick={() => void setMode('simple')}>{t('Einfach')}</button>
          <button type="button" className="modebar__btn" aria-pressed={mode === 'expert'} disabled={modeSaving} onClick={() => void setMode('expert')}>{t('Experte')}</button>
        </div>
      </div>
      {mode === 'expert' && (
      <div className="etabs" role="tablist" aria-label={t('Job-Einstellungen')}>
        {tabLabels.map((tb) => (
          <button
            key={tb.id}
            type="button"
            role="tab"
            id={'jobtab-' + tb.id}
            className="etab"
            aria-selected={tab === tb.id}
            aria-controls={'jobpanel-' + tb.id}
            tabIndex={tab === tb.id ? 0 : -1}
            onClick={() => setTab(tb.id)}
            onKeyDown={(e) => onTabKey(e, tb.id)}
          >
            {tb.label}
            {errorTabs.has(tb.id) && <span className="etab__err" role="img" aria-label={t('enthält Fehler')} />}
          </button>
        ))}
      </div>
      )}

      {mode === 'simple' && errorTabs.size > 0 && !errorTabs.has('general') && (
        <Alert tone="err">{t('In den erweiterten Optionen steht ein Fehler. Wechsle in den Experten-Modus, um ihn zu beheben.')}</Alert>
      )}
      <div role="tabpanel" id="jobpanel-general" aria-labelledby="jobtab-general" className="etabpanel" hidden={tab !== 'general'}>
            <section className="card" aria-label={t('Allgemein')}>
        <h2>{t('Allgemein')}</h2>
        <FormField label={t('Name')} error={errors.name}>
          {(aria) => <input {...aria} className="input" name="name" required maxLength={100} value={form.name} onChange={(e) => set('name', e.target.value)} />}
        </FormField>
        <div className="grid-2">
          <FormField label={t('Art')} hint={editing ? t('Die Art eines Jobs lässt sich nicht mehr ändern.') : canEditShell(profile) ? undefined : t('Befehle auszuführen darf nur, wer Shell-Jobs uneingeschränkt bearbeiten darf.')}>
            {(aria) => (
              <select {...aria} className="input" name="job-type" value={type} disabled={editing} onChange={(e) => {
                  const next: JobType = e.target.value === 'shell' ? 'shell' : 'http';
                  setType(next);
                  set('timeout', String(Math.min(next === 'shell' ? SHELL_DEFAULT_TIMEOUT : 30, next === 'shell' ? (limits.shell_max_timeout_seconds ?? 3600) : limits.max_timeout_seconds)));
                }}
              >
                <option value="http" disabled={!editing && !canEditHttp(profile)}>
                  {t('Adresse aufrufen (HTTP)')}</option>
                <option value="shell" disabled={!canEditShell(profile)}>
                  {t('Befehl ausführen (Shell)')}</option>
              </select>
            )}
          </FormField>
          <FormField label={t('Kategorie')} error={errors.category_id} hint={t('Bestimmt, wer den Job sehen und ändern darf.')}>
            {(aria) => (
              <select {...aria} className="input" name="category" value={form.categoryId} onChange={(e) => set('categoryId', e.target.value)}>
                {unrestricted && <option value="">{t('Ohne Kategorie')}</option>}
                {categories.map((category) => (
                  <option key={category.id} value={String(category.id)}>
                    {category.name}
                  </option>
                ))}
              </select>
            )}
          </FormField>
        </div>
        <label className="check">
          <input type="checkbox" checked={form.isEnabled} onChange={(e) => set('isEnabled', e.target.checked)} />
          <span>{t('Job ist aktiv und läuft nach Zeitplan')}</span>
        </label>
      </section>

      {shellActive && (
              <ShellSections part="general"
          form={shell}
          onChange={(patch) => setShell((current) => ({ ...current, ...patch }))}
          targets={shellTargets.targets}
          targetsError={shellTargets.error}
          errors={errors}
          saved={job?.shell ?? null}
          replacing={replacingScript}
          draft={script}
          setDraft={setScript}
          nextKey={() => envKey.current++}
          revealed={revealed}
          onStartReplacing={() => {
            setScript(EMPTY_SCRIPT);
            setReplacingScript(true);
          }}
          onCancelReplacing={() => {
            setScript(EMPTY_SCRIPT);
            setReplacingScript(false);
            setRevealed(false);
            setErrors((current) => Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith('script'))));
          }}
        />
      )}

            {!shellActive && (
        <section className="card" aria-label={t('Anfrage')}>
          <h2>{t('Anfrage')}</h2>
          <FormField label={t('Methode')} error={errors['http.method']}>
            {(aria) => (
              <select {...aria} className="input input--short" value={form.method} onChange={(e) => set('method', e.target.value as HttpMethod)}>
                {METHODS.map((method) => (
                  <option key={method} value={method}>
                    {method}
                  </option>
                ))}
              </select>
            )}
          </FormField>

          {!replacing && job?.http != null && (
            <div className="stored">
              <div className="stored__label">{t('Gespeicherte Anfrage')}</div>
              <code className="display-url" data-testid="display-url">
                {job.http.display_url ?? ''}
              </code>
              <div className="stored__facts">
                {job.http.header_count !== undefined && <span>{job.http.header_count > 0 ? t('Header: {n} (Werte verborgen)', { n: job.http.header_count }) : t('Header: keine')}</span>}
                {job.http.has_body !== undefined && <span>{job.http.has_body ? 'Body: ja (verborgen)' : 'Body: nein'}</span>}
              </div>
              <p className="hint">
                {t('Teile der URL, die wie Geheimnisse aussehen, werden ausgeblendet. Reine Kleinbuchstaben-Wörter bleiben sichtbar — Tokens gehören in einen Header oder in den Query-Wert. Die vollständige Adresse lässt sich nicht mehr anzeigen.')}</p>
              {errors.request !== undefined && <div className="field__error">{errors.request}</div>}
              <div className="row">
                <button type="button" className="btn btn--ghost" onClick={startReplacing}>
                  {t('Anfrage ersetzen')}</button>
              </div>
            </div>
          )}

          {replacing && (
            <div className="replace">
              {editing && <Alert tone="info">{t('URL, Header und Body werden zusammen ersetzt. Felder, die du leer lässt, sind danach leer.')}</Alert>}
              {!editing && (
                <details className="curlimport">
                  <summary>{t('Aus curl-Befehl übernehmen')}</summary>
                  <FormField label={t('curl-Befehl')} hint={t('Zum Beispiel „Als cURL kopieren“ aus den Entwicklerwerkzeugen. Es wird nur ausgelesen, nichts ausgeführt oder gesendet.')}>
                    {(aria) => <textarea {...aria} className="input input--mono-plain" rows={4} name="curl-import" autoComplete="off" spellCheck={false} value={curlText} onChange={(e) => setCurlText(e.target.value)} />}
                  </FormField>
                  <div className="row">
                    <button type="button" className="btn btn--ghost btn--small" disabled={curlText.trim() === ''} onClick={importCurl}>
                      {t('Übernehmen')}</button>
                  </div>
                </details>
              )}
              {curlNote !== null && (
                <div className="curlnote" role="status">
                  <Alert tone={curlNote.tone === 'ok' ? 'info' : 'err'}>{curlNote.text}</Alert>
                </div>
              )}
              <FormField
                label={t('URL')}
                error={errors['request.url'] ?? errors.request}
                hint={t('Teile der URL, die wie Geheimnisse aussehen, werden in der Anzeige ausgeblendet. Reine Kleinbuchstaben-Wörter bleiben sichtbar — Tokens gehören in einen Header oder in den Query-Wert.')}
              >
                {(aria) => (
                  <input
                    {...aria}
                    className="input input--mono-plain"
                    name="request-url"
                    type="text"
                    inputMode="url"
                    autoComplete="off"
                    autoCapitalize="off"
                    spellCheck={false}
                    placeholder={t('https://beispiel.de/pfad')}
                    value={draft.url}
                    onChange={(e) => setDraft({ ...draft, url: e.target.value })}
                  />
                )}
              </FormField>


              {editing && (
                <div className="row">
                  <button type="button" className="btn btn--ghost" onClick={cancelReplacing}>
                    {t('Abbrechen (gespeicherte Anfrage behalten)')}</button>
                </div>
              )}
            </div>
          )}
        </section>
      )}

      <section className="card" aria-label={t('Zeitplan')}>
        <h2>{t('Zeitplan')}</h2>
        <ScheduleBuilder cron={form.cron} onChange={(value) => set('cron', value)} minSeconds={limits?.min_interval_seconds ?? 10} />
        <div className="grid-2">
          <FormField label={t('Cron-Ausdruck')} error={errors.cron} hint={t('Fünf Felder: Minute Stunde Tag Monat Wochentag, oder „@every 30s“ für feste Sekunden-Abstände. Direkt änderbar; der Baukasten oben folgt, soweit er den Ausdruck abbilden kann.')}>
            {(aria) => <input {...aria} className="input input--mono" name="cron" spellCheck={false} autoComplete="off" value={form.cron} onChange={(e) => set('cron', e.target.value)} />}
          </FormField>
          <FormField label={t('Zeitzone')} error={errors.timezone}>
            {(aria) => (
              <select {...aria} className="input" name="timezone" value={form.timezone} onChange={(e) => set('timezone', e.target.value)}>
                {timezones(form.timezone).map((zone) => (
                  <option key={zone} value={zone}>
                    {zone}
                  </option>
                ))}
              </select>
            )}
          </FormField>
        </div>
        <SchedulePreview cron={form.cron} timezone={form.timezone} />
        <label className="check">
          <input type="checkbox" checked={form.expiresEnabled} onChange={(e) => set('expiresEnabled', e.target.checked)} />
          <span>{t('Zeitplan läuft ab: danach werden keine weiteren Läufe geplant')}</span>
        </label>
        {form.expiresEnabled && (
          <FormField label={'Letzter Zeitpunkt (Ortszeit ' + form.timezone + ')'} error={errors.expires_at}>
            {(aria) => <input {...aria} className="input" type="datetime-local" name="expires_at" value={form.expiresLocal} onChange={(e) => set('expiresLocal', e.target.value)} />}
          </FormField>
        )}
        <label className="check">
          <input type="checkbox" checked={form.catchUp} onChange={(e) => set('catchUp', e.target.checked)} />
          <span>{t('Verpassten Lauf nachholen (einmal), wenn der Scheduler zur Zeit nicht lief')}</span>
        </label>
      </section>
      </div>

      <div role="tabpanel" id="jobpanel-advanced" aria-labelledby="jobtab-advanced" className="etabpanel" hidden={tab !== 'advanced'}>
      {!shellActive && (
            <section className="card" aria-label={t('Anfrage-Details')}>
              <h2>{t('Header und Body')}</h2>
              {!replacing ? (
                <div className="stored">
                  <div className="stored__facts">
                    {job?.http?.header_count !== undefined && <span>{job.http.header_count > 0 ? t('Header: {n} (Werte verborgen)', { n: job.http.header_count }) : t('Header: keine')}</span>}
                    {job?.http?.has_body !== undefined && <span>{job.http.has_body ? 'Body: ja (verborgen)' : 'Body: nein'}</span>}
                  </div>
                  <p className="hint">{t('URL, Header und Body werden nur zusammen ersetzt. Wähle dazu im Reiter „Allgemein“ „Anfrage ersetzen“.')}</p>
                </div>
              ) : (
                <div className="replace">
                                <fieldset className="authbox">
                  <legend className="field__label">{t('Authentifizierung')}</legend>
                  <p className="hint">{t('Setzt den Header „Authorization“ für dich. Die Zugangsdaten werden danach nur noch verschlüsselt gespeichert und nie wieder angezeigt.')}</p>
                  <div className="grid-2">
                    <FormField label={t('Art')}>
                      {(aria) => (
                        <select {...aria} className="input" name="auth-mode" value={auth.mode} onChange={(e) => setAuth({ ...auth, mode: e.target.value === 'bearer' ? 'bearer' : 'basic' })}>
                          <option value="basic">{t('Basic (Benutzer und Passwort)')}</option>
                          <option value="bearer">{t('Bearer-Token')}</option>
                        </select>
                      )}
                    </FormField>
                    {auth.mode === 'basic' && (
                      <FormField label={t('Benutzer')}>
                        {(aria) => <input {...aria} className="input" name="auth-user" autoComplete="off" value={auth.user} onChange={(e) => setAuth({ ...auth, user: e.target.value })} />}
                      </FormField>
                    )}
                  </div>
                  <FormField label={auth.mode === 'basic' ? t('Passwort') : 'Token'}>
                    {(aria) => <input {...aria} className="input" type="password" name="auth-secret" autoComplete="new-password" value={auth.secret} onChange={(e) => setAuth({ ...auth, secret: e.target.value })} />}
                  </FormField>
                  <div className="row">
                    <button type="button" className="btn btn--ghost btn--small" disabled={auth.secret === '' || (auth.mode === 'basic' && (auth.user === '' || auth.user.includes(':')))} onClick={applyAuth}>
                      {t('Als Header übernehmen')}</button>
                  </div>
                </fieldset>
                <fieldset className="headers">
                <legend className="field__label">{t('Header')}</legend>
                {draft.headers.length === 0 && <p className="hint">{t('Keine Header. Werte werden verschlüsselt gespeichert und nie wieder angezeigt.')}</p>}
                {draft.headers.map((header, index) => (
                  <div key={header.key} className="row headers__row">
                    <input
                      className="input input--mono-plain"
                      aria-label={'Header ' + String(index + 1) + ' Name'}
                      placeholder={t('Authorization')}
                      autoComplete="off"
                      spellCheck={false}
                      value={header.name}
                      onChange={(e) => setDraft({ ...draft, headers: draft.headers.map((h) => (h.key === header.key ? { ...h, name: e.target.value } : h)) })}
                    />
                    <input
                      className="input input--mono-plain"
                      type={revealed ? 'text' : 'password'}
                      aria-label={t('Wert des Headers {n}', { n: index + 1 })}
                      placeholder={t('Wert')}
                      autoComplete="new-password"
                      spellCheck={false}
                      value={header.value}
                      onChange={(e) => setDraft({ ...draft, headers: draft.headers.map((h) => (h.key === header.key ? { ...h, value: e.target.value } : h)) })}
                    />
                    <button type="button" className="btn btn--ghost btn--small" onClick={() => setDraft({ ...draft, headers: draft.headers.filter((h) => h.key !== header.key) })}>
                      {t('Entfernen')}</button>
                  </div>
                ))}
                {headerErrors.map(([key, message]) => (
                  <div key={key} className="field__error">
                    {message}
                  </div>
                ))}
                <button type="button" className="btn btn--ghost btn--small" onClick={addHeader}>
                  {t('Header hinzufügen')}</button>
              </fieldset>

              {bodyAllowed ? (
                <FormField label={t('Body')} error={errors['request.body']} hint={revealed ? t('Wird verschlüsselt gespeichert.') : t('Wird verschlüsselt gespeichert und nie wieder angezeigt.')}>
                  {(aria) => (
                    <textarea
                      {...aria}
                      className="input input--area"
                      name="request-body"
                      rows={4}
                      autoComplete="off"
                      spellCheck={false}
                      value={draft.body}
                      onChange={(e) => setDraft({ ...draft, body: e.target.value })}
                    />
                  )}
                </FormField>
              ) : (
                <p className="hint">{t('{method} sendet keinen Body.', { method: form.method })}</p>
              )}
                </div>
              )}
            </section>
          )}

      {shellActive && (
              <ShellSections part="advanced"
          form={shell}
          onChange={(patch) => setShell((current) => ({ ...current, ...patch }))}
          targets={shellTargets.targets}
          targetsError={shellTargets.error}
          errors={errors}
          saved={job?.shell ?? null}
          replacing={replacingScript}
          draft={script}
          setDraft={setScript}
          nextKey={() => envKey.current++}
          revealed={revealed}
          onStartReplacing={() => {
            setScript(EMPTY_SCRIPT);
            setReplacingScript(true);
          }}
          onCancelReplacing={() => {
            setScript(EMPTY_SCRIPT);
            setReplacingScript(false);
            setRevealed(false);
            setErrors((current) => Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith('script'))));
          }}
        />
      )}

            <section className="card" aria-label={t('Ausführung')}>
        <h2>{t('Ausführung')}</h2>
        <div className="grid-2">
          <FormField label={t('Zeitlimit in Sekunden')} error={errors[shellActive ? 'shell.timeout_seconds' : 'http.timeout_seconds']} hint={t('Höchstens {n} s (Einstellung des Administrators).', { n: maxTimeout })}>
            {(aria) => <input {...aria} className="input" name="timeout" inputMode="numeric" value={form.timeout} onChange={(e) => set('timeout', e.target.value)} />}
          </FormField>
          {!shellActive && (
            <FormField label={t('Erwartete Statuscodes')} error={errors['http.expected_status']} hint={t('z. B. 200-299 oder 200,204,301-302')}>
              {(aria) => <input {...aria} className="input input--mono" name="expected" spellCheck={false} value={form.expected} onChange={(e) => set('expected', e.target.value)} />}
            </FormField>
          )}
        </div>
        {!shellActive && timeoutNumber !== null && timeoutNumber >= LONG_TIMEOUT && (
          <Alert tone="warn">
            {t('Alle Jobs laufen derzeit nacheinander. Ein Lauf mit langem Zeitlimit kann andere Läufe so lange aufhalten; Termine, die dabei mehr als 5 Minuten überfällig werden, gelten als verpasst.')}</Alert>
        )}
        <div className="grid-2">
          {!shellActive && (
            <FormField label={t('Weiterleitungen folgen (0–5)')} error={errors['http.max_redirects']}>
              {(aria) => <input {...aria} className="input" name="redirects" inputMode="numeric" value={form.redirects} onChange={(e) => set('redirects', e.target.value)} />}
            </FormField>
          )}
          <FormField label={t('Wenn der Job noch läuft')} error={errors.overlap_policy}>
            {(aria) => (
              <select {...aria} className="input" value={form.overlap} onChange={(e) => set('overlap', e.target.value as OverlapPolicy)}>
                <option value="skip">{t('Neuen Lauf überspringen')}</option>
                <option value="queue">{t('Neuen Lauf hinten anstellen')}</option>
                <option value="parallel">{t('Parallel starten')}</option>
              </select>
            )}
          </FormField>
        </div>
        <div className="grid-2">
          <FormField label={t('Wiederholungen bei Fehler (0–10)')} error={errors.retry_count}>
            {(aria) => <input {...aria} className="input" name="retries" inputMode="numeric" value={form.retryCount} onChange={(e) => set('retryCount', e.target.value)} />}
          </FormField>
          <FormField label={t('Abstand der Wiederholungen in Sekunden')} error={errors.retry_delay_seconds} hint={t('Wächst bei jeder Wiederholung; höchstens 3600 s.')}>
            {(aria) => <input {...aria} className="input" name="retry-delay" inputMode="numeric" value={form.retryDelay} onChange={(e) => set('retryDelay', e.target.value)} />}
          </FormField>
        </div>
        {!shellActive && (
          <FormField
            label={t('Antwort im Verlauf speichern (max. 64 KB)')}
            error={errors['http.store_response']}
            hint={storeNever ? 'Vom Administrator abgeschaltet.' : t('Gespeichert wird nur ein maskierter, gekürzter Textauszug.')}
          >
            {(aria) => (
              <select {...aria} className="input input--short" disabled={storeNever} value={storeNever ? 'off' : form.store} onChange={(e) => set('store', e.target.value as StoreResponse)}>
                <option value="inherit">{inheritLabel}</option>
                <option value="on">{t('An')}</option>
                <option value="off">{t('Aus')}</option>
              </select>
            )}
          </FormField>
        )}
      </section>
      </div>

      <div role="tabpanel" id="jobpanel-notify" aria-labelledby="jobtab-notify" className="etabpanel" hidden={tab !== 'notify'}>
        <section className="card" aria-label={t('Benachrichtigungen')}>
          <h2>{t('Benachrichtigungen')}</h2>
          <p className="hint">{t('Meldet Meridian, wenn dieser Job fehlschlägt und wenn er wieder läuft. Die Kanäle (E-Mail, ntfy) richtet ein Administrator unter Einstellungen ein.')}</p>
          <label className="check">
            <input type="checkbox" checked={form.notifyFailure} onChange={(e) => set('notifyFailure', e.target.checked)} />
            <span>{t('Mich benachrichtigen, wenn die Ausführung fehlschlägt')}</span>
          </label>
          {form.notifyFailure && (
            <FormField label={t('Benachrichtigung nach … Fehlschlägen in Folge')} error={errors['notify.failure_after']} hint={t('Ein Fehlschlag zählt erst, wenn kein Wiederholungsversuch mehr folgt. Gemeldet wird einmal je Serie.')}>
              {(aria) => <input {...aria} className="input" name="notify-after" inputMode="numeric" value={form.notifyAfter} onChange={(e) => set('notifyAfter', e.target.value)} />}
            </FormField>
          )}
          <label className="check">
            <input type="checkbox" checked={form.notifyRecovery} onChange={(e) => set('notifyRecovery', e.target.checked)} />
            <span>{t('Mich benachrichtigen, wenn die Ausführung wieder erfolgreich ist, nachdem sie fehlschlug')}</span>
          </label>
          <label className="check">
            <input type="checkbox" checked={form.disableEnabled} onChange={(e) => set('disableEnabled', e.target.checked)} />
            <span>{t('Job automatisch deaktivieren, wenn er mehrfach in Folge fehlschlägt')}</span>
          </label>
          {form.disableEnabled && (
            <div className="stack">
              <FormField label={t('Deaktivieren nach … Fehlschlägen in Folge')} error={errors['notify.disable_after']} hint={t('Bis 1000. Danach plant Meridian keine Läufe mehr, bis du den Job wieder aktivierst. Alte Fehlschläge deaktivieren nie nachträglich.')}>
                {(aria) => <input {...aria} className="input" name="disable-after" inputMode="numeric" value={form.disableAfter} onChange={(e) => set('disableAfter', e.target.value)} />}
              </FormField>
              <label className="check">
                <input type="checkbox" checked={form.notifyDisable} onChange={(e) => set('notifyDisable', e.target.checked)} />
                <span>{t('Mich benachrichtigen, wenn der Job deaktiviert wurde')}</span>
              </label>
            </div>
          )}
          <label className="check">
            <input type="checkbox" checked={form.runtimeEnabled} onChange={(e) => set('runtimeEnabled', e.target.checked)} />
            <span>{t('Mich benachrichtigen, wenn der Job länger als üblich braucht')}</span>
          </label>
          {form.runtimeEnabled && (
            <FormField label={t('Warnen ab … Prozent der üblichen Laufzeit')} error={errors['notify.runtime_warn_percent']} hint={t('110 bis 1000. 200 bedeutet doppelt so lange. Vergleich mit dem Median der letzten 20 erfolgreichen Läufe, ab fünf Vergleichswerten. Testläufe zählen nicht. Die Warnung erfolgt nach dem Lauf.')}>
              {(aria) => <input {...aria} className="input" name="runtime-percent" inputMode="numeric" value={form.runtimePercent} onChange={(e) => set('runtimePercent', e.target.value)} />}
            </FormField>
          )}
          {!shellActive && (
            <>
              <label className="check">
                <input type="checkbox" checked={form.tlsEnabled} onChange={(e) => set('tlsEnabled', e.target.checked)} />
                <span>{t('Mich warnen, bevor das TLS-Zertifikat des Ziels abläuft')}</span>
              </label>
              {form.tlsEnabled && (
                <FormField label={t('Warnen … Tage vor dem Ablauf')} error={errors['notify.tls_warn_days']} hint={t('1 bis 90. Das Ablaufdatum wird bei jedem erfolgreichen Verbindungsaufbau des Jobs gemerkt; gewarnt wird einmal je Zertifikat.')}>
                  {(aria) => <input {...aria} className="input" name="tls-days" inputMode="numeric" value={form.tlsDays} onChange={(e) => set('tlsDays', e.target.value)} />}
                </FormField>
              )}
            </>
          )}
          <FormField label={t('E-Mail-Empfänger für diesen Job')} error={errors['notify.recipients']} hint={t('Eine Adresse pro Zeile, höchstens 10. Leer = Empfänger der Kategorie, sonst des SMTP-Kanals. Eigene Empfänger ersetzen die Vorgabe und gelten für alle gewählten E-Mail-Kanäle.')}>
            {(aria) => <textarea {...aria} className="input" name="notify-recipients" rows={3} value={form.notifyRecipients} onChange={(e) => set('notifyRecipients', e.target.value)} />}
          </FormField>
          <fieldset className="radios">
            <legend className="field__label">{t('Benachrichtigen über …')}</legend>
            {(['all', 'selected', 'none'] as const).map((mode) => (
              <label key={mode} className="check">
                <input type="radio" name="notify-channels" checked={form.notifyChannels === mode} onChange={() => set('notifyChannels', mode)} />
                <span>{mode === 'all' ? t('alle Kanäle') : mode === 'selected' ? t('nur ausgewählte Kanäle') : t('keine Kanäle')}</span>
              </label>
            ))}
          </fieldset>
          {errors['notify.channels'] !== undefined && <p className="field__error">{errors['notify.channels']}</p>}
          {form.notifyChannels === 'selected' && (
            <div className="stack">
              {channelNames.length === 0 && <p className="hint">{t('Es gibt noch keinen Kanal. Ein Administrator legt sie unter Einstellungen → Benachrichtigungen an.')}</p>}
              {channelNames.map((channel) => (
                <label key={channel.id} className="check">
                  <input
                    type="checkbox"
                    checked={form.notifyIds.includes(channel.id)}
                    onChange={(e) => set('notifyIds', e.target.checked ? [...form.notifyIds, channel.id] : form.notifyIds.filter((id) => id !== channel.id))}
                  />
                  <span>
                    {channel.name} ({{ email: 'E-Mail', ntfy: 'ntfy', webhook: 'Webhook', telegram: 'Telegram' }[channel.type]}){!channel.enabled ? ' – ausgeschaltet' : ''}
                  </span>
                </label>
              ))}
              {errors['notify.channel_ids'] !== undefined && <p className="field__error">{errors['notify.channel_ids']}</p>}
            </div>
          )}
          {errors.notify !== undefined && <p className="field__error">{errors.notify}</p>}
        </section>
      </div>

      <div className="row editor__actions editor__actions--sticky">
        <button type="submit" className="btn btn--solid" disabled={busy}>
          {busy ? t('Speichert …') : t('Speichern')}
        </button>
        {editing && (
          <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => void submit('test')}>
            {t('Speichern und testen')}</button>
        )}
        <a className="btn btn--ghost" href={hrefOf(editing ? 'jobs/' + String(id) : 'jobs')}>
          {t('Abbrechen')}</a>
        {editing && <Confirm label={t('Löschen')} question={t('Job samt Verlauf endgültig löschen?')} confirmLabel={t('Ja, löschen')} busy={busy} onConfirm={() => void remove()} />}
      </div>
    </form>
  );
}
