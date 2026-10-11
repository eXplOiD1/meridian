import { useEffect, useState } from 'react';
import { Alert } from '../components/Alert';
import { request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatDuration } from '../lib/jobs';
import { hrefOf } from '../lib/useHashRoute';
import type { Profile, Stats } from '../types';

const RANGES = [7, 30, 90, 180, 366] as const;
const BAR_HEIGHT = 120;

function percent(value: number | null): string {
  return value === null ? '–' : value.toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' %';
}

/** Tägliche Läufe als gestapelte Säulen (erfolgreich grün, Fehler rot), gezeichnet als SVG ohne Inline-Stile. */
function DailyChart({ stats }: { stats: Stats }) {
  const max = Math.max(1, ...stats.daily.map((row) => row.runs));
  const count = stats.daily.length;
  const step = Math.max(1, Math.ceil(count / 8));
  return (
    <figure className="stats-chart">
      <svg viewBox={'0 0 ' + String(count) + ' ' + String(BAR_HEIGHT + 14)} preserveAspectRatio="none" role="img" aria-label={'Läufe pro Tag, ' + String(count) + ' Tage'} className="stats-chart__svg">
        {stats.daily.map((row, index) => {
          const ok = (row.ok / max) * BAR_HEIGHT;
          const bad = (row.failed / max) * BAR_HEIGHT;
          return (
            <g key={row.day}>
              <title>{row.day + ': ' + String(row.runs) + ' Läufe, ' + String(row.failed) + ' fehlgeschlagen'}</title>
              <rect x={index + 0.1} y={BAR_HEIGHT - ok - bad} width={0.8} height={bad} className="stats-bar stats-bar--bad" />
              <rect x={index + 0.1} y={BAR_HEIGHT - ok} width={0.8} height={ok} className="stats-bar stats-bar--ok" />
            </g>
          );
        })}
        <rect x={0} y={BAR_HEIGHT} width={count} height={0.4} className="stats-axis" />
      </svg>
      <div className="stats-chart__labels" aria-hidden="true">
        {stats.daily.filter((_, index) => index % step === 0).map((row) => (
          <span key={row.day}>{row.day.slice(8, 10) + '.' + row.day.slice(5, 7) + '.'}</span>
        ))}
      </div>
      <figcaption className="muted">Höchster Tag: {max.toLocaleString('de-DE')} Läufe. Tage in UTC.</figcaption>
    </figure>
  );
}

/** Statistik über Tage und Jobs; lädt beim Öffnen und beim Wechsel des Zeitraums. */
export function Statistics(_props: { profile: Profile }) {
  const [days, setDays] = useState<number>(30);
  const [stats, setStats] = useState<Stats | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setStats(null);
    request<Stats>('GET', '/api/stats?days=' + String(days))
      .then((data) => {
        if (!cancelled) {
          setStats(data);
          setError(null);
        }
      })
      .catch((caught: unknown) => {
        if (!cancelled) {
          setError(errorMessage(caught));
        }
      });
    return () => {
      cancelled = true;
    };
  }, [days]);

  const total = stats?.daily.reduce((sum, row) => sum + row.runs, 0) ?? 0;
  const failed = stats?.daily.reduce((sum, row) => sum + row.failed, 0) ?? 0;

  return (
    <section className="card" aria-label="Statistik">
      <h2>Statistik</h2>
      <p className="card__lead">Beendete Läufe der Jobs, die du sehen darfst. Testläufe und übersprungene Termine zählen nicht.</p>
      <div className="row">
        <label className="field field--inline">
          <span className="field__label">Zeitraum</span>
          <select value={days} onChange={(event) => setDays(Number.parseInt(event.target.value, 10))}>
            {RANGES.map((value) => (
              <option key={value} value={value}>
                {value === 366 ? '1 Jahr' : 'Letzte ' + String(value) + ' Tage'}
              </option>
            ))}
          </select>
        </label>
      </div>
      {error !== null && <Alert tone="err">{error}</Alert>}
      {stats === null && error === null && <p className="card__lead">Lädt …</p>}
      {stats !== null && (
        <>
          {days > stats.retention_days && (
            <Alert tone="info">
              Einzelne Läufe werden {stats.retention_days} Tage aufbewahrt, ältere nur als Tageswerte. Die Summen unten enthalten beides; Einzelheiten älterer Läufe gibt es nicht mehr.
            </Alert>
          )}
          <dl className="stats stats--row">
            <div>
              <dt>Läufe</dt>
              <dd>{total.toLocaleString('de-DE')}</dd>
            </div>
            <div>
              <dt>fehlgeschlagen</dt>
              <dd className={failed > 0 ? 'stats__bad' : undefined}>{failed.toLocaleString('de-DE')}</dd>
            </div>
            <div>
              <dt>Erfolgsquote</dt>
              <dd>{total === 0 ? '–' : percent(((total - failed) * 100) / total)}</dd>
            </div>
          </dl>
          <DailyChart stats={stats} />
          <h3>Je Job</h3>
          {stats.jobs.length === 0 ? (
            <p className="card__lead">In diesem Zeitraum gab es keine beendeten Läufe.</p>
          ) : (
            <div className="table-wrap">
              <table className="table">
                <caption className="visually-hidden">Läufe je Job im gewählten Zeitraum</caption>
                <thead>
                  <tr>
                    <th scope="col">Job</th>
                    <th scope="col">Läufe</th>
                    <th scope="col">Fehlgeschlagen</th>
                    <th scope="col">Erfolgsquote</th>
                    <th scope="col">Mittlere Dauer</th>
                    <th scope="col">Längste Dauer</th>
                  </tr>
                </thead>
                <tbody>
                  {stats.jobs.map((job) => (
                    <tr key={job.id}>
                      <td>
                        <a href={hrefOf('jobs/' + String(job.id))}>{job.name}</a>
                      </td>
                      <td>{job.runs.toLocaleString('de-DE')}</td>
                      <td>{job.failed.toLocaleString('de-DE')}</td>
                      <td>{percent(job.success_rate)}</td>
                      <td>{formatDuration(job.avg_duration_ms)}</td>
                      <td>{formatDuration(job.max_duration_ms)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </section>
  );
}
