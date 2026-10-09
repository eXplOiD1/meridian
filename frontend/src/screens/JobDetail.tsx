import { useCallback, useEffect, useState } from 'react';
import { Alert } from '../components/Alert';
import { Confirm } from '../components/Confirm';
import { RunBadge } from '../components/RunBadge';
import { TargetText } from '../components/TargetText';
import { request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatShort, formatTime } from '../lib/format';
import { formatDuration, isFinished, overlapLabel, presetName, STATE_LABEL, stateOf, TRIGGER_LABEL } from '../lib/jobs';
import { takePendingRun } from '../lib/pending';
import { triggerRun } from '../lib/runs';
import { hrefOf, navigate } from '../lib/useHashRoute';
import type { JobDetail as Job, Profile, Run, RunPage } from '../types';

const PAGE_SIZE = 20;
const POLL_MS = 2000;
const EXTRA_WAIT_S = 60;

interface Tracked {
  runId: number;
  kind: 'manual' | 'test';
  /** Wartezeit in Sekunden, nach der die Abfrage aufgibt (Zeitlimit + 60 s). */
  giveUpAfter: number;
}

/** Lauf-Ausgabe immer als Text: React setzt sie als Textknoten, nie als HTML. */
function RunDetail({ run, heading }: { run: Run; heading: string }) {
  return (
    <div className="rundetail" aria-label={heading}>
      <div className="rundetail__head">
        <RunBadge status={run.status} />
        <span className="muted">
          {TRIGGER_LABEL[run.trigger]} · Versuch {run.attempt}
          {run.http_status !== null ? ' · HTTP ' + String(run.http_status) : ''} · {formatDuration(run.duration_ms)}
        </span>
      </div>
      <div className="muted">
        Gestartet: {run.started_at === null ? '–' : formatTime(run.started_at)}
        {run.started_by !== null ? ' von ' + run.started_by.display_name : ''}
      </div>
      {run.note !== null && run.note !== '' && <p className="rundetail__note">{run.note}</p>}
      <div className="field__label">Ausgabe</div>
      {run.output === undefined || run.output === null || run.output === '' ? <p className="hint">Keine Ausgabe gespeichert.</p> : <pre className="runout">{run.output}</pre>}
    </div>
  );
}

export function JobDetail({ profile, id }: { profile: Profile; id: string }) {
  const [job, setJob] = useState<Job | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [runs, setRuns] = useState<Run[]>([]);
  const [nextBefore, setNextBefore] = useState<number | null>(null);
  const [historyError, setHistoryError] = useState<string | null>(null);
  const [selected, setSelected] = useState<Run | null>(null);
  const [tracked, setTracked] = useState<Tracked | null>(null);
  const [tracking, setTracking] = useState<Run | null>(null);
  const [gaveUp, setGaveUp] = useState(false);

  const loadJob = useCallback(async (): Promise<void> => {
    try {
      setJob((await request<{ job: Job }>('GET', '/api/jobs/' + id)).job);
    } catch (caught) {
      setLoadError(errorMessage(caught));
    }
  }, [id]);

  const loadHistory = useCallback(
    async (before: number | null, append: boolean): Promise<void> => {
      try {
        const query = new URLSearchParams({ limit: String(PAGE_SIZE) });
        if (before !== null) {
          query.set('before_id', String(before));
        }
        const page = await request<RunPage>('GET', '/api/jobs/' + id + '/runs?' + query.toString());
        setRuns((current) => (append ? [...current, ...page.runs] : page.runs));
        setNextBefore(page.next_before_id);
        setHistoryError(null);
      } catch (caught) {
        setHistoryError(errorMessage(caught));
      }
    },
    [id],
  );

  useEffect(() => {
    void loadJob();
    void loadHistory(null, false);
  }, [loadJob, loadHistory]);

  // Ein im Editor ausgelöster Testlauf („Speichern und testen“) wird hier weiterverfolgt.
  useEffect(() => {
    const pending = takePendingRun(id);
    if (pending !== null) {
      setTracked({ runId: pending.runId, kind: pending.kind, giveUpAfter: 360 });
      setNotice('Der Testlauf wurde eingereiht und startet im nächsten Takt (wenige Sekunden).');
    }
  }, [id]);

  // Ergebnis eines ausgelösten Laufs abfragen: alle 2 s, bis er abgeschlossen ist oder die Wartezeit um ist.
  useEffect(() => {
    if (tracked === null) {
      return undefined;
    }
    let cancelled = false;
    let timer = 0;
    const startedAt = Date.now();
    setTracking(null);
    setGaveUp(false);
    async function poll(): Promise<void> {
      try {
        const data = await request<{ run: Run }>('GET', '/api/runs/' + String(tracked?.runId));
        if (cancelled) {
          return;
        }
        setTracking(data.run);
        if (isFinished(data.run.status)) {
          setTracked(null);
          void loadHistory(null, false);
          void loadJob();
          return;
        }
      } catch (caught) {
        if (!cancelled) {
          setActionError(errorMessage(caught));
          setTracked(null);
          return;
        }
      }
      if (!cancelled && (Date.now() - startedAt) / 1000 > (tracked?.giveUpAfter ?? 0)) {
        setGaveUp(true);
        setTracked(null);
        return;
      }
      if (!cancelled) {
        timer = window.setTimeout(() => void poll(), POLL_MS);
      }
    }
    void poll();
    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [tracked, loadHistory, loadJob]);

  async function start(kind: 'manual' | 'test'): Promise<void> {
    if (job === null) {
      return;
    }
    setBusy(true);
    setActionError(null);
    setNotice(null);
    setSelected(null);
    try {
      const runId = await triggerRun(kind, id, profile.csrf_token);
      const limit = job.http?.timeout_seconds ?? 30;
      setTracked({ runId, kind, giveUpAfter: limit + EXTRA_WAIT_S + 10 });
      setNotice(kind === 'test' ? 'Der Testlauf wurde eingereiht und startet im nächsten Takt (wenige Sekunden).' : 'Der Lauf wurde eingereiht und startet im nächsten Takt (wenige Sekunden).');
    } catch (caught) {
      setActionError(errorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  async function toggle(): Promise<void> {
    if (job === null) {
      return;
    }
    setBusy(true);
    setActionError(null);
    try {
      const data = await request<{ job: Job }>('POST', '/api/jobs/' + id + (job.is_enabled ? '/disable' : '/enable'), { csrf: profile.csrf_token });
      setJob(data.job);
    } catch (caught) {
      setActionError(errorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  async function remove(): Promise<void> {
    setBusy(true);
    setActionError(null);
    try {
      await request('DELETE', '/api/jobs/' + id, { csrf: profile.csrf_token });
      navigate('jobs');
    } catch (caught) {
      setActionError(errorMessage(caught));
      setBusy(false);
    }
  }

  async function select(run: Run): Promise<void> {
    setSelected(run);
    try {
      setSelected((await request<{ run: Run }>('GET', '/api/runs/' + String(run.id))).run);
    } catch (caught) {
      setActionError(errorMessage(caught));
    }
  }

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
  if (job === null) {
    return <div className="card__lead">Lädt …</div>;
  }

  const state = stateOf(job);
  const preset = presetName(job.cron);
  const http = job.http;
  const waiting = tracked !== null;

  return (
    <>
      <p className="crumbs">
        <a href={hrefOf('jobs')}>← Alle Jobs</a>
      </p>
      <section className="card" aria-label="Job">
        <div className="jobhead">
          <div className="jobhead__main">
            <h2>{job.name}</h2>
            <div className="muted">
              {http !== null ? <TargetText target={http.target} /> : '–'}
              {job.category !== null ? ' · ' + job.category.name : ' · ohne Kategorie'}
              {job.owner !== null ? ' · Besitzer: ' + job.owner.display_name : ''}
            </div>
            <span className={'jobstate jobstate--' + state}>{STATE_LABEL[state]}</span>
          </div>
          <div className="row jobhead__actions">
            {job.can.run && (
              <button type="button" className="btn btn--solid" disabled={busy || waiting || !job.is_enabled} onClick={() => void start('manual')} title={job.is_enabled ? undefined : 'Nur aktive Jobs lassen sich manuell ausführen.'}>
                Jetzt ausführen
              </button>
            )}
            {job.can.run && (
              <button type="button" className="btn btn--ghost" disabled={busy || waiting} onClick={() => void start('test')}>
                Testlauf
              </button>
            )}
            {job.can.edit && (
              <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => void toggle()}>
                {job.is_enabled ? 'Deaktivieren' : 'Aktivieren'}
              </button>
            )}
            {job.can.edit && (
              <a className="btn btn--ghost" href={hrefOf('jobs/' + String(job.id) + '/bearbeiten')}>
                Bearbeiten
              </a>
            )}
            {job.can.edit && <Confirm label="Löschen" question="Job samt Verlauf endgültig löschen?" confirmLabel="Ja, löschen" busy={busy} onConfirm={() => void remove()} />}
          </div>
        </div>

        {actionError !== null && <Alert tone="err">{actionError}</Alert>}
        {notice !== null && <Alert tone="info">{notice}</Alert>}

        <dl className="facts">
          <dt>Zeitplan</dt>
          <dd>
            {preset !== null ? preset + ' · ' : ''}
            <code className="cron cron--inline">{job.cron}</code> ({job.timezone})
          </dd>
          <dt>Nächster Lauf</dt>
          <dd>{job.is_enabled ? formatShort(job.next_run_at) : 'deaktiviert'}</dd>
          {http !== null && (
            <>
              <dt>Anfrage</dt>
              <dd>
                {http.method} an <TargetText target={http.target} />
                {/* Header und Body nennt der Server nur mit Bearbeitungsrecht; ohne fehlen die Felder und werden nicht gedeutet. */}
                {job.can.edit && http.header_count !== undefined && http.has_body !== undefined
                  ? ' · Header: ' + String(http.header_count) + ' · Body: ' + (http.has_body ? 'ja' : 'nein')
                  : http.has_request === true
                    ? ' · Anfrage gesetzt'
                    : ''}
              </dd>
              <dt>Zeitlimit</dt>
              <dd>{http.timeout_seconds} s · erwartet {http.expected_status} · Weiterleitungen: {http.max_redirects}</dd>
            </>
          )}
          <dt>Bei Überlappung</dt>
          <dd>
            {overlapLabel(job.overlap_policy)} · Wiederholungen: {job.retry_count}
            {job.catch_up ? ' · verpasste Läufe werden nachgeholt' : ''}
          </dd>
        </dl>
      </section>

      {(tracking !== null || waiting || gaveUp) && (
        <section className="card" aria-label="Ausgelöster Lauf" aria-live="polite">
          <h2>{tracking?.trigger === 'test' || tracked?.kind === 'test' ? 'Testlauf' : 'Lauf'}</h2>
          {(tracking?.trigger === 'test' || tracked?.kind === 'test') && <p className="card__lead">Ein Testlauf zählt nicht in die Statistik und gilt nicht als „letzter Lauf“.</p>}
          {tracking === null && waiting && <p className="hint">Wartet auf den nächsten Takt …</p>}
          {tracking !== null && !isFinished(tracking.status) && (
            <p className="hint">
              <RunBadge status={tracking.status} /> – Ergebnis wird alle 2 Sekunden abgefragt.
            </p>
          )}
          {gaveUp && <Alert tone="warn">Der Lauf läuft noch – später im Verlauf nachsehen.</Alert>}
          {tracking !== null && isFinished(tracking.status) && <RunDetail run={tracking} heading="Ergebnis" />}
        </section>
      )}

      <section className="card" aria-label="Verlauf">
        <h2>Verlauf</h2>
        {historyError !== null && <Alert tone="err">{historyError}</Alert>}
        {runs.length === 0 && historyError === null && <p className="card__lead">Noch keine Läufe.</p>}
        {runs.length > 0 && (
          <div className="table-wrap">
            <table className="table table--runs">
              <caption className="visually-hidden">Läufe dieses Jobs, neueste zuerst</caption>
              <thead>
                <tr>
                  <th scope="col">Zeit</th>
                  <th scope="col">Status</th>
                  <th scope="col">Auslöser</th>
                  <th scope="col">HTTP</th>
                  <th scope="col">Dauer</th>
                </tr>
              </thead>
              <tbody>
                {runs.map((run) => (
                  <tr key={run.id} className={selected?.id === run.id ? 'is-selected' : undefined}>
                    <td>
                      <button type="button" className="linkbtn" aria-expanded={selected?.id === run.id} onClick={() => void select(run)}>
                        {formatTime(run.started_at ?? run.scheduled_for ?? '')}
                      </button>
                    </td>
                    <td>
                      <RunBadge status={run.status} />
                    </td>
                    <td>{TRIGGER_LABEL[run.trigger]}</td>
                    <td>{run.http_status ?? '–'}</td>
                    <td>{formatDuration(run.duration_ms)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {nextBefore !== null && (
          <div className="row">
            <button type="button" className="btn btn--ghost" onClick={() => void loadHistory(nextBefore, true)}>
              Mehr laden
            </button>
          </div>
        )}
        {selected !== null && <RunDetail run={selected} heading="Lauf-Details" />}
      </section>
    </>
  );
}
