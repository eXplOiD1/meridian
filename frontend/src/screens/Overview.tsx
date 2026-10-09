import { useCallback, useEffect, useState } from 'react';
import { Alert } from '../components/Alert';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatNext } from '../lib/format';
import { TargetText } from '../components/TargetText';
import { STATE_LABEL, stateOf } from '../lib/jobs';
import { canViewJobs } from '../lib/permissions';
import { hrefOf, navigate } from '../lib/useHashRoute';
import type { JobList, JobSummary, Profile } from '../types';

const BOARD_ROWS = 12;
const REFRESH_MS = 30_000;

/** Uhr der Übersichtstafel: eigener kleiner Zustand, damit nur sie jede Sekunde neu gezeichnet wird. */
function BoardClock() {
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 1000);
    return () => window.clearInterval(timer);
  }, []);
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    <div className="board__clock" role="timer" aria-label={'Uhrzeit ' + pad(now.getHours()) + ':' + pad(now.getMinutes())}>
      <span aria-hidden="true">
        {pad(now.getHours())}
        <span className="board__colon blink">:</span>
        {pad(now.getMinutes())}
      </span>
    </div>
  );
}

/** Die nächsten aktiven Jobs; lädt alle 30 Sekunden neu. Anzeige des Ziels nur als Host. */
function useUpcoming(enabled: boolean): { jobs: JobSummary[] | null; error: string | null } {
  const [jobs, setJobs] = useState<JobSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (): Promise<void> => {
    try {
      const data = await request<JobList>('GET', '/api/jobs?sort=next_run&enabled=1');
      setJobs(data.jobs.slice(0, BOARD_ROWS));
      setError(null);
    } catch (caught) {
      // Eine abgelaufene Sitzung behandelt der App-Rahmen; hier nur echte Fehler anzeigen.
      if (!(caught instanceof ApiError && caught.status === 401)) {
        setError(errorMessage(caught));
      }
    }
  }, []);

  useEffect(() => {
    if (!enabled) {
      return undefined;
    }
    void load();
    const timer = window.setInterval(() => void load(), REFRESH_MS);
    return () => window.clearInterval(timer);
  }, [enabled, load]);

  return { jobs, error };
}

export function Overview({ profile }: { profile: Profile }) {
  const mayView = canViewJobs(profile);
  const { jobs, error } = useUpcoming(mayView);

  return (
    <>
      {!profile.totp_enabled && (
        <Alert tone="warn">
          Dein Konto ist noch nicht mit einem zweiten Faktor geschützt.{' '}
          <a
            href="#/konto"
            onClick={(event) => {
              event.preventDefault();
              navigate('konto');
            }}
          >
            Jetzt einrichten
          </a>
          .
        </Alert>
      )}
      <section className="board" aria-label="Nächste Läufe">
        <div className="board__head">
          <div>
            <div className="board__title">NÄCHSTE LÄUFE</div>
            <div className="board__sub">Aktive Jobs, sortiert nach dem nächsten Ausführungszeitpunkt</div>
          </div>
          <BoardClock />
        </div>
        {error !== null && <Alert tone="err">{error}</Alert>}
        {jobs !== null && jobs.length > 0 && (
          <div className="board__scroll">
            <table className="board__table">
              <caption className="visually-hidden">Nächste Läufe aktiver Jobs</caption>
              <thead>
                <tr>
                  <th scope="col">Zeit</th>
                  <th scope="col">Job</th>
                  <th scope="col">Kategorie</th>
                  <th scope="col">Ziel</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
                {jobs.map((job) => {
                  const state = stateOf(job);
                  return (
                    <tr key={job.id}>
                      <td className="board__time">{formatNext(job.next_run_at)}</td>
                      <td>
                        <a className="board__link" href={hrefOf('jobs/' + String(job.id))}>
                          {job.name}
                        </a>
                      </td>
                      <td>{job.category?.name ?? '–'}</td>
                      <td className="board__host">{job.http === null ? '–' : <TargetText target={job.http.target} />}</td>
                      <td>
                        <span className={'board__state board__state--' + state + (state === 'running' ? ' pulse' : '')}>{STATE_LABEL[state]}</span>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        {jobs !== null && jobs.length === 0 && (
          <div className="board__empty">
            <strong>Noch keine aktiven Jobs</strong>
            <span>Sobald ein Job angelegt und aktiv ist, erscheint er hier mit Zeitpunkt, Kategorie, Ziel und Status.</span>
          </div>
        )}
        {jobs === null && error === null && mayView && <div className="board__empty"><span>Lädt …</span></div>}
        {!mayView && (
          <div className="board__empty">
            <strong>Keine Jobs sichtbar</strong>
            <span>Deine Rolle darf keine Jobs ansehen.</span>
          </div>
        )}
      </section>
    </>
  );
}
