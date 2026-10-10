import { useCallback, useEffect, useState } from 'react';
import { Alert } from '../components/Alert';
import { RunBadge } from '../components/RunBadge';
import { request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatTime } from '../lib/format';
import { formatDuration, RUN_STATUS, TRIGGER_LABEL } from '../lib/jobs';
import { hrefOf } from '../lib/useHashRoute';
import type { Profile, Run, RunStatus } from '../types';

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
export function History(_props: { profile: Profile }) {
  const [runs, setRuns] = useState<HistoryRun[]>([]);
  const [nextBefore, setNextBefore] = useState<number | null>(null);
  const [status, setStatus] = useState<'' | RunStatus>('');
  const [error, setError] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);

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

  useEffect(() => {
    setLoaded(false);
    void load(null, false);
  }, [load]);

  return (
    <section className="card" aria-label="Verlauf aller Jobs">
      <h2>Verlauf</h2>
      <p className="card__lead">Die letzten Läufe aller Jobs, die du sehen darfst, neueste zuerst.</p>
      <div className="row">
        <label className="field field--inline">
          <span className="field__label">Status</span>
          <select value={status} onChange={(event) => setStatus(event.target.value as '' | RunStatus)}>
            <option value="">Alle</option>
            {STATUS_ORDER.map((value) => (
              <option key={value} value={value}>
                {RUN_STATUS[value].label}
              </option>
            ))}
          </select>
        </label>
      </div>
      {error !== null && <Alert tone="err">{error}</Alert>}
      {loaded && error === null && runs.length === 0 && <p className="card__lead">Keine Läufe{status !== '' ? ' mit diesem Status' : ''}.</p>}
      {!loaded && error === null && <p className="card__lead">Lädt …</p>}
      {runs.length > 0 && (
        <div className="table-wrap">
          <table className="table table--runs">
            <caption className="visually-hidden">Läufe aller sichtbaren Jobs, neueste zuerst</caption>
            <thead>
              <tr>
                <th scope="col">Zeit</th>
                <th scope="col">Job</th>
                <th scope="col">Kategorie</th>
                <th scope="col">Status</th>
                <th scope="col">Auslöser</th>
                <th scope="col">Ergebnis</th>
                <th scope="col">Dauer</th>
              </tr>
            </thead>
            <tbody>
              {runs.map((run) => (
                <tr key={run.id}>
                  <td>{formatTime(run.started_at ?? run.scheduled_for ?? '')}</td>
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
              ))}
            </tbody>
          </table>
        </div>
      )}
      {nextBefore !== null && (
        <div className="row">
          <button type="button" className="btn btn--ghost" onClick={() => void load(nextBefore, true)}>
            Mehr laden
          </button>
        </div>
      )}
    </section>
  );
}
