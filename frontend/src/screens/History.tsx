/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { Fragment, useCallback, useEffect, useState } from 'react';
import { Alert } from '../components/Alert';
import { RunBadge } from '../components/RunBadge';
import { RunDetail } from '../components/RunDetail';
import { RunLog } from '../components/RunLog';
import { request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatTime } from '../lib/format';
import { formatDuration, isFinished, RUN_STATUS, TRIGGER_LABEL } from '../lib/jobs';
import { canRun } from '../lib/permissions';
import { hrefOf } from '../lib/useHashRoute';
import type { Profile, Run, RunStatus } from '../types';
import { t } from '../i18n';

/** Lauf der globalen Verlaufsliste: wie `Run`, dazu Job und Kategorie (Antwort von `GET /api/runs`). */
interface HistoryRun extends Run {
  job: { id: number; name: string; type: 'http' | 'shell' };
  category: { name: string } | null;
}

interface HistoryPage {
  runs: HistoryRun[];
  next_before_id: number | null;
}

const PAGE_SIZE = 50;
const STATUS_ORDER: RunStatus[] = ['queued', 'running', 'ok', 'failed', 'timeout', 'aborted', 'skipped'];

/** Verlauf über alle Jobs, die der Benutzer sehen darf (der Server kappt auf die erlaubten Kategorien). */
export function History({ profile }: { profile: Profile }) {
  const [runs, setRuns] = useState<HistoryRun[]>([]);
  const [nextBefore, setNextBefore] = useState<number | null>(null);
  const [status, setStatus] = useState<'' | RunStatus>('');
  const [error, setError] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [selected, setSelected] = useState<HistoryRun | null>(null);

  const load = useCallback(
    async (before: number | null, append: boolean): Promise<void> => {
      const query = new URLSearchParams({ limit: String(PAGE_SIZE) });
      if (status !== '') {
        query.set('status', status);
      }
      if (before !== null) {
        query.set('before_id', String(before));
      }
      try {
        const page = await request<HistoryPage>('GET', '/api/runs?' + query.toString());
        setRuns((current) => (append ? [...current, ...page.runs] : page.runs));
        setNextBefore(page.next_before_id);
        setError(null);
      } catch (caught) {
        setError(errorMessage(caught));
      } finally {
        setLoaded(true);
      }
    },
    [status],
  );

  async function select(run: HistoryRun): Promise<void> {
    setSelected(run);
    try {
      const detail = (await request<{ run: Run }>('GET', '/api/runs/' + String(run.id))).run;
      setSelected({ ...run, ...detail });
    } catch (caught) {
      setError(errorMessage(caught));
    }
  }

  function onSelectedFinished(run: Run): void {
    setSelected((current) => (current === null ? current : { ...current, ...run }));
    void load(null, false);
  }

  useEffect(() => {
    setLoaded(false);
    void load(null, false);
  }, [load]);

  return (
    <section className="card" aria-label={t('Verlauf aller Jobs')}>
      <h2>{t('Verlauf')}</h2>
      <p className="card__lead">{t('Die letzten Läufe aller Jobs, die du sehen darfst, neueste zuerst.')}</p>
      <div className="row">
        <label className="field field--inline">
          <span className="field__label">{t('Status')}</span>
          <select className="select" value={status} onChange={(event) => {
              setSelected(null);
              setStatus(event.target.value as '' | RunStatus);
            }}>
            <option value="">{t('Alle')}</option>
            {STATUS_ORDER.map((value) => (
              <option key={value} value={value}>
                {RUN_STATUS[value].label}
              </option>
            ))}
          </select>
        </label>
      </div>
      {error !== null && <Alert tone="err">{error}</Alert>}
      {loaded && error === null && runs.length === 0 && <p className="card__lead">{status !== '' ? t('Keine Läufe mit diesem Status.') : t('Keine Läufe.')}</p>}
      {!loaded && error === null && <p className="card__lead">{t('Lädt …')}</p>}
      {runs.length > 0 && (
        <div className="table-wrap">
          <table className="table table--runs">
            <caption className="visually-hidden">{t('Läufe aller sichtbaren Jobs, neueste zuerst')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('Zeit')}</th>
                <th scope="col">{t('Job')}</th>
                <th scope="col">{t('Kategorie')}</th>
                <th scope="col">{t('Status')}</th>
                <th scope="col">{t('Auslöser')}</th>
                <th scope="col">{t('Ergebnis')}</th>
                <th scope="col">{t('Dauer')}</th>
              </tr>
            </thead>
            <tbody>
              {runs.map((run) => (
                <Fragment key={run.id}>
                <tr className={selected?.id === run.id ? 'is-selected' : undefined}>
                  <td>
                    <button type="button" className="linkbtn" aria-expanded={selected?.id === run.id} onClick={() => void select(run)}>
                      {formatTime(run.started_at ?? run.scheduled_for ?? '')}
                    </button>
                  </td>
                  <td>
                    <a href={hrefOf('jobs/' + String(run.job.id))}>{run.job.name}</a>
                  </td>
                  <td>{run.category?.name ?? '–'}</td>
                  <td>
                    <RunBadge status={run.status} />
                  </td>
                  <td>{TRIGGER_LABEL[run.trigger]}</td>
                  <td>{run.job.type === 'shell' ? (run.exit_code != null ? 'Exit-Code ' + String(run.exit_code) : '–') : run.http_status != null ? 'HTTP ' + String(run.http_status) : '–'}</td>
                  <td>{formatDuration(run.duration_ms)}</td>
                </tr>
                {selected?.id === run.id && (
                  <tr className="rundetail-row">
                    <td colSpan={7}>
                      {!isFinished(selected.status) ? (
                        <RunLog key={selected.id} runId={selected.id} csrf={profile.csrf_token} canCancel={canRun(profile, selected.category?.name ?? null)} onFinished={onSelectedFinished} />
                      ) : (
                        <RunDetail run={selected} heading={t('Lauf-Details: {name}', { name: selected.job.name })} />
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
          <button type="button" className="btn btn--ghost" onClick={() => void load(nextBefore, true)}>
            {t('Mehr laden')}</button>
        </div>
      )}
    </section>
  );
}
