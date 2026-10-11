import { useCallback, useEffect, useState } from 'react';
import { Alert } from '../components/Alert';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatNext } from '../lib/format';
import { shellTargetLabel } from '../components/ShellSections';
import { TargetText } from '../components/TargetText';
import { STATE_LABEL, stateOf } from '../lib/jobs';
import { canViewJobs } from '../lib/permissions';
import { hrefOf, navigate } from '../lib/useHashRoute';
import { TaktBand } from '../components/TaktBand';
import { formatDuration } from '../lib/jobs';
import { formatTime } from '../lib/format';
import { t } from '../i18n';
import type { JobList, JobSummary, Overview as OverviewData, Profile } from '../types';

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

/** Tagesübersicht („Heute im Takt“, Aufmerksamkeit, Summen) für den lokalen Tag; lädt jede Minute neu. */
function useToday(enabled: boolean): { data: OverviewData | null; from: Date; error: string | null } {
  const [data, setData] = useState<OverviewData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [from, setFrom] = useState(() => startOfToday());

  const load = useCallback(async (): Promise<void> => {
    const start = startOfToday();
    const end = new Date(start.getFullYear(), start.getMonth(), start.getDate() + 1, 0, 0, 0);
    try {
      const query = new URLSearchParams({ from: start.toISOString().replace(/\.\d{3}Z$/, 'Z'), to: end.toISOString().replace(/\.\d{3}Z$/, 'Z') });
      setData(await request<OverviewData>('GET', '/api/overview?' + query.toString()));
      setFrom(start);
      setError(null);
    } catch (caught) {
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
    const timer = window.setInterval(() => void load(), 60_000);
    return () => window.clearInterval(timer);
  }, [enabled, load]);

  return { data, from, error };
}

function startOfToday(): Date {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0);
}


/** Restzeit bis zum nächsten Lauf als kurzer Text (nur aus echten Daten). */
function countdown(iso: string | null, now: number): { value: string; unit: string } | null {
  if (iso === null) {
    return null;
  }
  const ms = new Date(iso).getTime() - now;
  if (Number.isNaN(ms)) {
    return null;
  }
  if (ms < 60_000) {
    return { value: '<1', unit: t('Minute') };
  }
  const minutes = Math.round(ms / 60_000);
  if (minutes < 120) {
    return { value: String(minutes), unit: t('Minuten') };
  }
  const hours = Math.round(minutes / 60);
  return hours < 48 ? { value: String(hours), unit: t('Stunden') } : { value: String(Math.round(hours / 24)), unit: t('Tage') };
}

/** Kopfbereich: Lage in einem Satz, nächster Lauf als Ring. Alle Werte kommen aus der API, nichts ist erfunden. */
function Hero({ jobs, attention, mayView }: { jobs: JobSummary[] | null; attention: number | null; mayView: boolean }) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 30_000);
    return () => window.clearInterval(timer);
  }, []);
  const next = jobs !== null ? jobs.find((job) => job.next_run_at !== null) : undefined;
  const left = next !== undefined ? countdown(next.next_run_at, now) : null;
  let headline = t('Alles im Takt.');
  if (!mayView) {
    headline = t('Willkommen bei Meridian.');
  } else if (attention !== null && attention > 0) {
    headline = attention === 1 ? t('Ein Job braucht deine Aufmerksamkeit.') : t('{n} Jobs brauchen deine Aufmerksamkeit.', { n: attention });
  } else if (jobs !== null && jobs.length === 0) {
    headline = t('Bereit für den ersten Job.');
  }
  return (
    <section className="hero" aria-label={t('Lage')}>
      <div>
        <div className="hero__eyebrow"><span className={'dot ' + (attention !== null && attention > 0 ? 'dot--err' : 'dot--now')} aria-hidden="true" />{t('Lage jetzt')}</div>
        <h2>{headline}</h2>
        <p>
          {!mayView
            ? t('Deine Rolle darf keine Jobs ansehen.')
            : next !== undefined
              ? t('Der nächste Lauf ist „{name}“ um {time}.', { name: next.name, time: formatNext(next.next_run_at) })
              : t('Es ist kein aktiver Job geplant.')}
        </p>
        {mayView && (
          <div className="hero__actions">
            <a className="btn btn--solid" href={hrefOf('jobs')}>{t('Alle Jobs')}</a>
            <a className="btn btn--ghost" href={hrefOf('verlauf')}>{t('Verlauf ansehen')}</a>
          </div>
        )}
      </div>
      {mayView && (
        <div className="hero__next">
          <div className="orbit" role="img" aria-label={left !== null ? t('Nächster Lauf in {n} {unit}', { n: left.value, unit: left.unit }) : t('Kein Lauf geplant')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
            <span>{t('Nächster Lauf')}</span>
            <strong>{left !== null ? left.value : '–'}</strong>
            <small>{left !== null ? left.unit : ''}</small>
          </div>
        </div>
      )}
    </section>
  );
}

export function Overview({ profile }: { profile: Profile }) {
  const mayView = canViewJobs(profile);
  const { jobs, error } = useUpcoming(mayView);
  const today = useToday(mayView);

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
      <Hero jobs={jobs} attention={today.data !== null ? today.data.attention.length : null} mayView={mayView} />
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
                      <td className="board__host">{job.http != null ? <TargetText target={job.http.target} /> : job.shell != null ? shellTargetLabel(job.shell.target.kind, job.shell.target.name) : '–'}</td>
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
      {today.error !== null && <Alert tone="err">{today.error}</Alert>}
      {today.data !== null && <TaktBand data={today.data} from={today.from} />}
      {today.data !== null && (
        <div className="overview-grid">
          <section className="card" aria-label="Braucht Aufmerksamkeit">
            <h2>Braucht Aufmerksamkeit</h2>
            {today.data.attention.length === 0 && <p className="card__lead">Nichts auffällig: Alle aktiven Jobs liefen zuletzt fehlerfrei.</p>}
            <ul className="attention">
              {today.data.attention.map((item) => (
                <li key={item.job.id} className="attention__item">
                  <a href={hrefOf(item.kind === 'monitor' ? 'ueberwachung' : 'jobs/' + String(item.job.id))}>{item.job.name}</a>{' '}
                  {item.status === 'down'
                    ? 'hat sich seit ' + (item.finished_at !== null ? formatTime(item.finished_at) : 'einiger Zeit') + ' nicht mehr gemeldet.'
                    : item.status === 'slow'
                    ? 'läuft schon ' + String(Math.round((item.running_seconds ?? 0) / 60)) + ' min, üblich sind etwa ' + String(Math.max(1, Math.round((item.usual_seconds ?? 0) / 60))) + ' min (braucht länger als üblich).'
                    : 'ist zuletzt ' + (item.status === 'timeout' ? 'wegen Zeitlimit ' : '') + 'fehlgeschlagen' + (item.finished_at !== null ? ' (' + formatTime(item.finished_at) + ').' : '.')}
                </li>
              ))}
            </ul>
          </section>
          <section className="card" aria-label="Heute bisher">
            <h2>Heute bisher</h2>
            <dl className="stats">
              <div>
                <dt>Läufe seit Mitternacht</dt>
                <dd>{today.data.totals.runs.toLocaleString('de-DE')}</dd>
              </div>
              <div>
                <dt>fehlgeschlagen</dt>
                <dd className={today.data.totals.failed > 0 ? 'stats__bad' : undefined}>{today.data.totals.failed.toLocaleString('de-DE')}</dd>
              </div>
              <div>
                <dt>mittlere Laufzeit</dt>
                <dd>{today.data.totals.avg_duration_ms === null ? '–' : formatDuration(today.data.totals.avg_duration_ms)}</dd>
              </div>
            </dl>
          </section>
        </div>
      )}
    </>
  );
}
