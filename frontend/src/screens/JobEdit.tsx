import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Alert } from '../components/Alert';
import { Confirm } from '../components/Confirm';
import { FormField } from '../components/FormField';
import { EMPTY_SCRIPT, ShellSections, shellFormFromJob, splitTarget, targetKey, useShellTargets } from '../components/ShellSections';
import type { ScriptDraft, ShellForm } from '../components/ShellSections';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { CRON_PRESETS } from '../lib/jobs';
import { canEditHttp, canEditShell } from '../lib/permissions';
import { setPendingRun } from '../lib/pending';
import { triggerRun } from '../lib/runs';
import { hrefOf, navigate } from '../lib/useHashRoute';
import type { CategoryRef, HttpMethod, JobDetail, JobLimits, JobType, OverlapPolicy, Profile, StoreResponse } from '../types';

const METHODS: HttpMethod[] = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];
const WITH_BODY: HttpMethod[] = ['POST', 'PUT', 'PATCH', 'DELETE'];
const LONG_TIMEOUT = 60;
const SHELL_DEFAULT_TIMEOUT = 300;

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
  store: StoreResponse;
}

/** Geheimnisfelder: nur schreibend, nie vorbefüllt, nach dem Absenden geleert. */
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
      return new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: timezone });
    } catch {
      return null;
    }
  }, [timezone]);
  return (
    <div className="preview" aria-live="polite">
      <div className="preview__title">NÄCHSTE LÄUFE · {timezone.toUpperCase()}</div>
      {error !== null && <div className="preview__error">{error}</div>}
      {error === null && runs === null && <div className="preview__row">Wird berechnet …</div>}
      {error === null &&
        runs?.map((iso) => (
          <div key={iso} className="preview__row">
            {format === null ? iso : format.format(new Date(iso))}
          </div>
        ))}
    </div>
  );
}

export function JobEdit({ profile, id }: { profile: Profile; id: string | null }) {
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
          <a href={hrefOf('jobs')}>Zurück zur Jobliste</a>
        </p>
      </>
    );
  }
  if (form === null || categories === null || limits === null || (editing && job === null)) {
    return <div className="card__lead">Lädt …</div>;
  }
  if (job !== null && !job.can.edit) {
    return (
      <>
        <Alert tone="warn">Du darfst diesen Job nicht ändern.</Alert>
        <p>
          <a href={hrefOf('jobs/' + String(job.id))}>Zurück zum Job</a>
        </p>
      </>
    );
  }
  if (!editing && !canEditHttp(profile) && !canEditShell(profile)) {
    return <Alert tone="warn">Du darfst keine Jobs anlegen.</Alert>;
  }
  if (editing && job !== null && job.type === 'shell' && !canEditShell(profile)) {
    return (
      <>
        <Alert tone="warn">Shell-Jobs dürfen nur Benutzer mit dem Recht „Shell-Jobs bearbeiten“ für alle Kategorien ändern.</Alert>
        <p>
          <a href={hrefOf('jobs/' + String(job.id))}>Zurück zum Job</a>
        </p>
      </>
    );
  }

  const maxTimeout = shellActive ? (limits.shell_max_timeout_seconds ?? 3600) : limits.max_timeout_seconds;
  const timeoutNumber = toInt(form.timeout);
  const bodyAllowed = WITH_BODY.includes(form.method);
  const unrestricted = shellActive ? canEditShell(profile) : canEditHttp(profile, null);
  const storeNever = limits.response_storage === 'never';
  const inheritLabel = 'Wie global (' + (limits.response_storage === 'on' ? 'an' : 'aus') + ')';

  const shownKeys = new Set([
    'name', 'category_id', 'cron', 'timezone', 'overlap_policy', 'retry_count', 'retry_delay_seconds', 'http.method',
    'http.timeout_seconds', 'http.expected_status', 'http.max_redirects', 'http.store_response', 'request.url', 'request.body',
    'shell.target', 'shell.interpreter', 'shell.user', 'shell.workdir', 'shell.timeout_seconds', 'script.source',
  ]);
  const headerErrors = Object.entries(errors).filter(([key]) => key.startsWith('request.headers'));
  const otherErrors = Object.entries(errors).filter(([key]) => !shownKeys.has(key) && !key.startsWith('request.headers') && !key.startsWith('script.env') && !(key === 'script' && editing && !replacingScript) && !(key === 'request' && editing && !replacing));

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
    setErrors((current) => Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith('request'))));
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
      local['http.max_redirects'] = 'Weiterleitungen: ganze Zahl von 0 bis 5.';
    }
    if (retryCount === null) {
      local.retry_count = 'Wiederholungen: ganze Zahl von 0 bis 10.';
    }
    if (retryDelay === null) {
      local.retry_delay_seconds = 'Abstand: ganze Zahl in Sekunden.';
    }
    if (!shellActive && replacing && draft.url.trim() === '') {
      local['request.url'] = 'Pflichtfeld: bitte die URL angeben.';
    }
    const picked = splitTarget(shell.target);
    if (shellActive && picked === null) {
      local['shell.target'] = 'Bitte einen Ausführungsort wählen.';
    }
    if (shellActive && replacingScript && script.source.trim() === '') {
      local['script.source'] = 'Pflichtfeld: bitte das Skript eingeben.';
    }
    if (Object.keys(local).length > 0 || timeout === null || (!shellActive && redirects === null) || retryCount === null || retryDelay === null) {
      setErrors(local);
      setFormError('Bitte die markierten Felder korrigieren.');
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
      {otherErrors.length > 0 && (
        <Alert tone="err">
          <ul className="errlist">
            {otherErrors.map(([key, message]) => (
              <li key={key}>{message}</li>
            ))}
          </ul>
        </Alert>
      )}

      <section className="card" aria-label="Allgemein">
        <h2>Allgemein</h2>
        <FormField label="Name" error={errors.name}>
          {(aria) => <input {...aria} className="input" name="name" required maxLength={100} value={form.name} onChange={(e) => set('name', e.target.value)} />}
        </FormField>
        <div className="grid-2">
          <FormField label="Art" hint={editing ? 'Die Art eines Jobs lässt sich nicht mehr ändern.' : canEditShell(profile) ? undefined : 'Befehle auszuführen darf nur, wer Shell-Jobs uneingeschränkt bearbeiten darf.'}>
            {(aria) => (
              <select {...aria} className="input" name="job-type" value={type} disabled={editing} onChange={(e) => {
                  const next: JobType = e.target.value === 'shell' ? 'shell' : 'http';
                  setType(next);
                  set('timeout', String(Math.min(next === 'shell' ? SHELL_DEFAULT_TIMEOUT : 30, next === 'shell' ? (limits.shell_max_timeout_seconds ?? 3600) : limits.max_timeout_seconds)));
                }}
              >
                <option value="http" disabled={!editing && !canEditHttp(profile)}>
                  Adresse aufrufen (HTTP)
                </option>
                <option value="shell" disabled={!canEditShell(profile)}>
                  Befehl ausführen (Shell)
                </option>
              </select>
            )}
          </FormField>
          <FormField label="Kategorie" error={errors.category_id} hint="Bestimmt, wer den Job sehen und ändern darf.">
            {(aria) => (
              <select {...aria} className="input" name="category" value={form.categoryId} onChange={(e) => set('categoryId', e.target.value)}>
                {unrestricted && <option value="">Ohne Kategorie</option>}
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
          <span>Job ist aktiv und läuft nach Zeitplan</span>
        </label>
      </section>

      {shellActive && (
        <ShellSections
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
          onStartReplacing={() => {
            setScript(EMPTY_SCRIPT);
            setReplacingScript(true);
          }}
          onCancelReplacing={() => {
            setScript(EMPTY_SCRIPT);
            setReplacingScript(false);
            setErrors((current) => Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith('script'))));
          }}
        />
      )}

      {!shellActive && (
        <section className="card" aria-label="Anfrage">
          <h2>Anfrage</h2>
          <FormField label="Methode" error={errors['http.method']}>
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
              <div className="stored__label">Gespeicherte Anfrage</div>
              <code className="display-url" data-testid="display-url">
                {job.http.display_url ?? ''}
              </code>
              <div className="stored__facts">
                {job.http.header_count !== undefined && <span>{job.http.header_count > 0 ? 'Header: ' + String(job.http.header_count) + ' (Werte verborgen)' : 'Header: keine'}</span>}
                {job.http.has_body !== undefined && <span>{job.http.has_body ? 'Body: ja (verborgen)' : 'Body: nein'}</span>}
              </div>
              <p className="hint">
                Teile der URL, die wie Geheimnisse aussehen, werden ausgeblendet. Reine Kleinbuchstaben-Wörter bleiben sichtbar — Tokens gehören in einen Header oder in den Query-Wert. Die vollständige Adresse lässt sich nicht mehr anzeigen.
              </p>
              {errors.request !== undefined && <div className="field__error">{errors.request}</div>}
              <div className="row">
                <button type="button" className="btn btn--ghost" onClick={startReplacing}>
                  Anfrage ersetzen
                </button>
              </div>
            </div>
          )}

          {replacing && (
            <div className="replace">
              {editing && <Alert tone="info">URL, Header und Body werden zusammen ersetzt. Felder, die du leer lässt, sind danach leer.</Alert>}
              <FormField
                label="URL"
                error={errors['request.url'] ?? errors.request}
                hint="Teile der URL, die wie Geheimnisse aussehen, werden in der Anzeige ausgeblendet. Reine Kleinbuchstaben-Wörter bleiben sichtbar — Tokens gehören in einen Header oder in den Query-Wert."
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
                    placeholder="https://beispiel.de/pfad"
                    value={draft.url}
                    onChange={(e) => setDraft({ ...draft, url: e.target.value })}
                  />
                )}
              </FormField>

              <fieldset className="headers">
                <legend className="field__label">Header</legend>
                {draft.headers.length === 0 && <p className="hint">Keine Header. Werte werden verschlüsselt gespeichert und nie wieder angezeigt.</p>}
                {draft.headers.map((header, index) => (
                  <div key={header.key} className="row headers__row">
                    <input
                      className="input input--mono-plain"
                      aria-label={'Header ' + String(index + 1) + ' Name'}
                      placeholder="Authorization"
                      autoComplete="off"
                      spellCheck={false}
                      value={header.name}
                      onChange={(e) => setDraft({ ...draft, headers: draft.headers.map((h) => (h.key === header.key ? { ...h, name: e.target.value } : h)) })}
                    />
                    <input
                      className="input input--mono-plain"
                      type="password"
                      aria-label={'Header ' + String(index + 1) + ' Wert'}
                      placeholder="Wert"
                      autoComplete="new-password"
                      spellCheck={false}
                      value={header.value}
                      onChange={(e) => setDraft({ ...draft, headers: draft.headers.map((h) => (h.key === header.key ? { ...h, value: e.target.value } : h)) })}
                    />
                    <button type="button" className="btn btn--ghost btn--small" onClick={() => setDraft({ ...draft, headers: draft.headers.filter((h) => h.key !== header.key) })}>
                      Entfernen
                    </button>
                  </div>
                ))}
                {headerErrors.map(([key, message]) => (
                  <div key={key} className="field__error">
                    {message}
                  </div>
                ))}
                <button type="button" className="btn btn--ghost btn--small" onClick={addHeader}>
                  Header hinzufügen
                </button>
              </fieldset>

              {bodyAllowed ? (
                <FormField label="Body" error={errors['request.body']} hint="Wird verschlüsselt gespeichert und nie wieder angezeigt.">
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
                <p className="hint">{form.method} sendet keinen Body.</p>
              )}

              {editing && (
                <div className="row">
                  <button type="button" className="btn btn--ghost" onClick={cancelReplacing}>
                    Abbrechen (gespeicherte Anfrage behalten)
                  </button>
                </div>
              )}
            </div>
          )}
        </section>
      )}

      <section className="card" aria-label="Zeitplan">
        <h2>Zeitplan</h2>
        <div className="chips" role="group" aria-label="Voreinstellungen">
          {CRON_PRESETS.map((preset) => (
            <button key={preset.cron} type="button" className="chip" aria-pressed={form.cron.trim() === preset.cron} onClick={() => set('cron', preset.cron)}>
              {preset.label}
            </button>
          ))}
        </div>
        <div className="grid-2">
          <FormField label="Cron-Ausdruck" error={errors.cron} hint="Fünf Felder: Minute Stunde Tag Monat Wochentag.">
            {(aria) => <input {...aria} className="input input--mono" name="cron" spellCheck={false} autoComplete="off" value={form.cron} onChange={(e) => set('cron', e.target.value)} />}
          </FormField>
          <FormField label="Zeitzone" error={errors.timezone}>
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
          <input type="checkbox" checked={form.catchUp} onChange={(e) => set('catchUp', e.target.checked)} />
          <span>Verpassten Lauf nachholen (einmal), wenn der Scheduler zur Zeit nicht lief</span>
        </label>
      </section>

      <section className="card" aria-label="Ausführung">
        <h2>Ausführung</h2>
        <div className="grid-2">
          <FormField label="Zeitlimit in Sekunden" error={errors[shellActive ? 'shell.timeout_seconds' : 'http.timeout_seconds']} hint={'Höchstens ' + String(maxTimeout) + ' s (Einstellung des Administrators).'}>
            {(aria) => <input {...aria} className="input" name="timeout" inputMode="numeric" value={form.timeout} onChange={(e) => set('timeout', e.target.value)} />}
          </FormField>
          {!shellActive && (
            <FormField label="Erwartete Statuscodes" error={errors['http.expected_status']} hint="z. B. 200-299 oder 200,204,301-302">
              {(aria) => <input {...aria} className="input input--mono" name="expected" spellCheck={false} value={form.expected} onChange={(e) => set('expected', e.target.value)} />}
            </FormField>
          )}
        </div>
        {!shellActive && timeoutNumber !== null && timeoutNumber >= LONG_TIMEOUT && (
          <Alert tone="warn">
            Alle Jobs laufen derzeit nacheinander. Ein Lauf mit langem Zeitlimit kann andere Läufe so lange aufhalten; Termine, die dabei mehr als 5 Minuten überfällig werden, gelten als verpasst.
          </Alert>
        )}
        <div className="grid-2">
          {!shellActive && (
            <FormField label="Weiterleitungen folgen (0–5)" error={errors['http.max_redirects']}>
              {(aria) => <input {...aria} className="input" name="redirects" inputMode="numeric" value={form.redirects} onChange={(e) => set('redirects', e.target.value)} />}
            </FormField>
          )}
          <FormField label="Wenn der Job noch läuft" error={errors.overlap_policy}>
            {(aria) => (
              <select {...aria} className="input" value={form.overlap} onChange={(e) => set('overlap', e.target.value as OverlapPolicy)}>
                <option value="skip">Neuen Lauf überspringen</option>
                <option value="queue">Neuen Lauf hinten anstellen</option>
                <option value="parallel">Parallel starten</option>
              </select>
            )}
          </FormField>
        </div>
        <div className="grid-2">
          <FormField label="Wiederholungen bei Fehler (0–10)" error={errors.retry_count}>
            {(aria) => <input {...aria} className="input" name="retries" inputMode="numeric" value={form.retryCount} onChange={(e) => set('retryCount', e.target.value)} />}
          </FormField>
          <FormField label="Abstand der Wiederholungen in Sekunden" error={errors.retry_delay_seconds} hint="Wächst bei jeder Wiederholung; höchstens 3600 s.">
            {(aria) => <input {...aria} className="input" name="retry-delay" inputMode="numeric" value={form.retryDelay} onChange={(e) => set('retryDelay', e.target.value)} />}
          </FormField>
        </div>
        {!shellActive && (
          <FormField
            label="Antwort im Verlauf speichern (max. 64 KB)"
            error={errors['http.store_response']}
            hint={storeNever ? 'Vom Administrator abgeschaltet.' : 'Gespeichert wird nur ein maskierter, gekürzter Textauszug.'}
          >
            {(aria) => (
              <select {...aria} className="input input--short" disabled={storeNever} value={storeNever ? 'off' : form.store} onChange={(e) => set('store', e.target.value as StoreResponse)}>
                <option value="inherit">{inheritLabel}</option>
                <option value="on">An</option>
                <option value="off">Aus</option>
              </select>
            )}
          </FormField>
        )}
      </section>

      <div className="row editor__actions">
        <button type="submit" className="btn btn--solid" disabled={busy}>
          {busy ? 'Speichert …' : 'Speichern'}
        </button>
        {editing && (
          <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => void submit('test')}>
            Speichern und testen
          </button>
        )}
        <a className="btn btn--ghost" href={hrefOf(editing ? 'jobs/' + String(id) : 'jobs')}>
          Abbrechen
        </a>
        {editing && <Confirm label="Löschen" question="Job samt Verlauf endgültig löschen?" confirmLabel="Ja, löschen" busy={busy} onConfirm={() => void remove()} />}
      </div>
    </form>
  );
}
