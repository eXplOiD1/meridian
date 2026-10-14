/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { Fragment, useCallback, useEffect, useState } from 'react';
import { Alert } from '../components/Alert';
import { Confirm } from '../components/Confirm';
import { RunBadge } from '../components/RunBadge';
import { RunDetail } from '../components/RunDetail';
import { RunLog } from '../components/RunLog';
import { shellTargetLabel } from '../components/ShellSections';
import { TargetText } from '../components/TargetText';
import { request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatShort, formatTime } from '../lib/format';
import { formatDuration, isFinished, overlapLabel, presetName, STATE_LABEL, stateOf, TRIGGER_LABEL } from '../lib/jobs';
import { takePendingRun } from '../lib/pending';
import { triggerRun } from '../lib/runs';
import { hrefOf, navigate } from '../lib/useHashRoute';
import type { JobDetail as Job, Profile, Run, RunPage } from '../types';
import { t } from '../i18n';

const PAGE_SIZE = 20;

interface Tracked {
  runId: number;
  kind: 'manual' | 'test';
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
      setTracked({ runId: pending.runId, kind: pending.kind });
      setNotice(t('Der Testlauf wurde eingereiht und startet im nächsten Takt (wenige Sekunden).'));
    }
  }, [id]);

  async function start(kind: 'manual' | 'test'): Promise<void> {
    if (job === null) {
      return;
    }
    setBusy(true);
    setActionError(null);
    setNotice(null);
    setSelected(null);
    setTracking(null);
    try {
      const runId = await triggerRun(kind, id, profile.csrf_token);
      setTracking(null);
      setTracked({ runId, kind });
      setNotice(kind === 'test' ? t('Der Testlauf wurde eingereiht und startet im nächsten Takt (wenige Sekunden).') : t('Der Lauf wurde eingereiht und startet im nächsten Takt (wenige Sekunden).'));
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

  function onTrackedFinished(run: Run): void {
    setTracking(run);
    setTracked(null);
    void loadHistory(null, false);
    void loadJob();
  }

  function onSelectedFinished(run: Run): void {
    setSelected(run);
    void loadHistory(null, false);
    void loadJob();
  }

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
  if (job === null) {
    return <div className="card__lead">{t('Lädt …')}</div>;
  }

  const state = stateOf(job);
  const preset = job.automation_only ? t('Nur durch Automation') : presetName(job.cron);
  const http = job.http ?? null;
  const waiting = tracked !== null;

  return (
    <>
      <p className="crumbs">
        <a href={hrefOf('jobs')}>{t('← Alle Jobs')}</a>
      </p>
      <section className="card" aria-label={t('Job')}>
        <div className="jobhead">
          <div className="jobhead__main">
            <h2>{job.name}</h2>
            <div className="muted">
              {http !== null ? <TargetText target={http.target} /> : '–'}
              {job.category !== null ? ' · ' + job.category.name : t(' · ohne Kategorie')}
              {job.owner !== null ? ' · Besitzer: ' + job.owner.display_name : ''}
            </div>
            <span className={'jobstate jobstate--' + state}>{STATE_LABEL[state]}</span>
          </div>
          <div className="row jobhead__actions">
            {job.can.run && (
              <button type="button" className="btn btn--solid" disabled={busy || waiting || !job.is_enabled} onClick={() => void start('manual')} title={job.is_enabled ? undefined : t('Nur aktive Jobs lassen sich manuell ausführen.')}>
                {t('Jetzt ausführen')}</button>
            )}
            {job.can.run && (
              <button type="button" className="btn btn--ghost" disabled={busy || waiting} onClick={() => void start('test')}>
                {t('Testlauf')}</button>
            )}
            {job.can.edit && (
              <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => void toggle()}>
                {job.is_enabled ? t('Deaktivieren') : t('Aktivieren')}
              </button>
            )}
            {job.can.edit && (
              <a className="btn btn--ghost" href={hrefOf('jobs/' + String(job.id) + '/bearbeiten')}>
                {t('Bearbeiten')}</a>
            )}
            {job.can.edit && <Confirm label={t('Löschen')} question={t('Job samt Verlauf endgültig löschen?')} confirmLabel={t('Ja, löschen')} busy={busy} onConfirm={() => void remove()} />}
          </div>
        </div>

        {actionError !== null && <Alert tone="err">{actionError}</Alert>}
        {notice !== null && <Alert tone="info">{notice}</Alert>}

        <dl className="facts">
          <dt>{t('Zeitplan')}</dt>
          <dd>
            {preset !== null ? preset + ' · ' : ''}
            <code className="cron cron--inline">{job.automation_only ? t('Kein eigener Zeitplan') : job.cron}</code> ({job.timezone})
          </dd>
          <dt>{t('Nächster Lauf')}</dt>
          <dd>{job.is_enabled ? formatShort(job.next_run_at) : t('deaktiviert')}</dd>
          {http !== null && (
            <>
              <dt>{t('Anfrage')}</dt>
              <dd>
                {t('{method} an', { method: http.method })} <TargetText target={http.target} />
                {/* Header und Body nennt der Server nur mit Bearbeitungsrecht; ohne fehlen die Felder und werden nicht gedeutet. */}
                {job.can.edit && http.header_count !== undefined && http.has_body !== undefined
                  ? ' · Header: ' + String(http.header_count) + ' · Body: ' + (http.has_body ? 'ja' : 'nein')
                  : http.has_request === true
                    ? t(' · Anfrage gesetzt')
                    : ''}
              </dd>
              <dt>{t('Zeitlimit')}</dt>
              <dd>{http.timeout_seconds} s · {t('erwartet')} {http.expected_status} · {t('Weiterleitungen:')} {http.max_redirects}</dd>
            </>
          )}
          {job.shell != null && (
            <>
              <dt>{t('Ausführungsort')}</dt>
              <dd>
                {shellTargetLabel(job.shell.target.kind, job.shell.target.name)}
                {job.shell.target.kind === 'docker' ? ' · ' + t('Benutzer:') + ' ' + (job.shell.user ?? t('Standard')) : ''}
                {job.shell.workdir != null ? ' · ' + t('Verzeichnis:') + ' ' + job.shell.workdir : ''}
              </dd>
              <dt>{t('Befehl')}</dt>
              <dd>
                {job.shell.interpreter} · {job.shell.has_script ? t('Skript gesetzt (Inhalt verborgen)') : t('kein Skript')}
                {job.shell.has_env ? ' · ' + t('Umgebungsvariablen:') + ' ' + String(job.shell.env_count) : ''}
              </dd>
              <dt>{t('Zeitlimit')}</dt>
              <dd>{job.shell.timeout_seconds} s</dd>
            </>
          )}
          <dt>{t('Bei Überlappung')}</dt>
          <dd>
            {overlapLabel(job.overlap_policy)} · {t('Wiederholungen:')} {job.retry_count}
            {job.catch_up ? ' · ' + t('verpasste Läufe werden nachgeholt') : ''}
          </dd>
          {job.expires_at !== null && (
            <>
              <dt>{t('Zeitplan läuft ab')}</dt>
              <dd>
                {formatTime(job.expires_at)}
                {new Date(job.expires_at).getTime() <= Date.now() ? t(' · abgelaufen, keine weiteren Läufe') : ''}
              </dd>
            </>
          )}
        </dl>
      </section>

      {(tracking !== null || waiting) && (
        <section className="card" aria-label={t('Ausgelöster Lauf')} aria-live="polite">
          <h2>{tracking?.trigger === 'test' || tracked?.kind === 'test' ? t('Testlauf') : t('Lauf')}</h2>
          {(tracking?.trigger === 'test' || tracked?.kind === 'test') && <p className="card__lead">{t('Ein Testlauf zählt nicht in die Statistik und gilt nicht als „letzter Lauf“.')}</p>}
          {tracked !== null && <RunLog key={tracked.runId} runId={tracked.runId} csrf={profile.csrf_token} canCancel={job.can.run} onFinished={onTrackedFinished} />}
          {tracking !== null && isFinished(tracking.status) && <RunDetail run={tracking} heading={t('Ergebnis')} />}
        </section>
      )}

      <section className="card" aria-label={t('Verlauf')}>
        <h2>{t('Verlauf')}</h2>
        {historyError !== null && <Alert tone="err">{historyError}</Alert>}
        {runs.length === 0 && historyError === null && <p className="card__lead">{t('Noch keine Läufe.')}</p>}
        {runs.length > 0 && (
          <div className="table-wrap">
            <table className="table table--runs">
              <caption className="visually-hidden">{t('Läufe dieses Jobs, neueste zuerst')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('Zeit')}</th>
                  <th scope="col">{t('Status')}</th>
                  <th scope="col">{t('Auslöser')}</th>
                  <th scope="col">{job.type === 'shell' ? t('Exit-Code') : 'HTTP'}</th>
                  <th scope="col">{t('Dauer')}</th>
                </tr>
              </thead>
              <tbody>
                {runs.map((run) => (
                  <Fragment key={run.id}>
                  <tr data-run-id={run.id} className={selected?.id === run.id ? 'is-selected' : undefined}>
                    <td>
                      <button type="button" className="linkbtn" aria-expanded={selected?.id === run.id} onClick={() => void select(run)}>
                        {formatTime(run.started_at ?? run.scheduled_for ?? '')}
                      </button>
                    </td>
                    <td>
                      <RunBadge status={run.status} />
                    </td>
                    <td>{TRIGGER_LABEL[run.trigger]}</td>
                    <td>{job.type === 'shell' ? (run.exit_code ?? '–') : (run.http_status ?? '–')}</td>
                    <td>{formatDuration(run.duration_ms)}</td>
                  </tr>
                  {selected?.id === run.id && (
                    <tr className="rundetail-row">
                      <td colSpan={5}>
                        {!isFinished(selected.status) ? (
                          <RunLog key={selected.id} runId={selected.id} csrf={profile.csrf_token} canCancel={job.can.run} onFinished={onSelectedFinished} />
                        ) : (
                          <RunDetail run={selected} heading={t('Lauf-Details')} />
                        )}
                      </td>
                    </tr>
                  )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {nextBefore !== null && (
          <div className="row">
            <button type="button" className="btn btn--ghost" onClick={() => void loadHistory(nextBefore, true)}>
              {t('Mehr laden')}</button>
          </div>
        )}
      </section>
    </>
  );
}
