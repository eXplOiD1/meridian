import { useEffect, useMemo, useState } from 'react';
import { Alert } from '../components/Alert';
import { RunBadge } from '../components/RunBadge';
import { request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatShort } from '../lib/format';
import { hostOf, presetName, STATE_LABEL, stateOf } from '../lib/jobs';
import type { JobState } from '../lib/jobs';
import { canEditHttp, isScoped } from '../lib/permissions';
import { hrefOf, navigate } from '../lib/useHashRoute';
import type { JobList, JobSummary, Profile } from '../types';

type StatusFilter = 'all' | JobState;

const STATUS_FILTERS: ReadonlyArray<{ value: StatusFilter; label: string }> = [
  { value: 'all', label: 'Alle' },
  { value: 'scheduled', label: 'Geplant' },
  { value: 'running', label: 'Läuft' },
  { value: 'failed', label: 'Mit Fehler' },
  { value: 'disabled', label: 'Deaktiviert' },
];

function matches(job: JobSummary, status: StatusFilter, category: string, search: string): boolean {
  if (status !== 'all' && stateOf(job) !== status) {
    return false;
  }
  if (category === '__none' ? job.category !== null : category !== '' && String(job.category?.id) !== category) {
    return false;
  }
  const needle = search.trim().toLowerCase();
  if (needle === '') {
    return true;
  }
  const haystack = [job.name, job.category?.name ?? '', job.http === null ? '' : hostOf(job.http.target), job.owner?.display_name ?? ''].join('\n').toLowerCase();
  return haystack.includes(needle);
}

export function Jobs({ profile }: { profile: Profile }) {
  const [data, setData] = useState<JobList | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<StatusFilter>('all');
  const [category, setCategory] = useState('');
  const [search, setSearch] = useState('');

  useEffect(() => {
    let cancelled = false;
    request<JobList>('GET', '/api/jobs')
      .then((list) => {
        if (!cancelled) {
          setData(list);
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
  }, []);

  const jobs = data?.jobs ?? [];
  const categories = useMemo(() => {
    const seen = new Map<number, string>();
    for (const job of jobs) {
      if (job.category !== null) {
        seen.set(job.category.id, job.category.name);
      }
    }
    return [...seen.entries()].sort((a, b) => a[1].localeCompare(b[1], 'de'));
  }, [jobs]);
  const hasUncategorised = jobs.some((job) => job.category === null);
  const counts = useMemo(() => {
    const result: Record<StatusFilter, number> = { all: jobs.length, scheduled: 0, running: 0, failed: 0, disabled: 0 };
    for (const job of jobs) {
      result[stateOf(job)] += 1;
    }
    return result;
  }, [jobs]);
  const shown = jobs.filter((job) => matches(job, status, category, search));

  return (
    <>
      <div className="toolbar">
        <div className="chips" role="group" aria-label="Nach Status filtern">
          {STATUS_FILTERS.map((entry) => (
            <button key={entry.value} type="button" className="chip" aria-pressed={status === entry.value} onClick={() => setStatus(entry.value)}>
              {entry.label} <span className="chip__count">{counts[entry.value]}</span>
            </button>
          ))}
        </div>
        {canEditHttp(profile) && (
          <a className="btn btn--solid" href={hrefOf('jobs/neu')}>
            Neuen Job anlegen
          </a>
        )}
      </div>

      <div className="row filters">
        <div className="field row__grow">
          <label className="field__label" htmlFor="job-search">
            Suche
          </label>
          <input id="job-search" className="input" type="search" placeholder="Name, Kategorie, Ziel oder Besitzer" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <div className="field filters__select">
          <label className="field__label" htmlFor="job-category">
            Kategorie
          </label>
          <select id="job-category" className="input" value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="">Alle Kategorien</option>
            {categories.map(([id, name]) => (
              <option key={id} value={String(id)}>
                {name}
              </option>
            ))}
            {hasUncategorised && <option value="__none">Ohne Kategorie</option>}
          </select>
        </div>
      </div>

      {isScoped(profile) && <p className="hint">Du siehst nur Jobs deiner Kategorien.</p>}
      {error !== null && <Alert tone="err">{error}</Alert>}
      {data?.truncated === true && <Alert tone="info">Es werden nur die ersten 500 Jobs angezeigt. Mit der Suche oder der Kategorie lässt sich die Liste eingrenzen.</Alert>}

      <section className="card" aria-label="Jobliste">
        {data === null && error === null && <p className="card__lead">Lädt …</p>}
        {data !== null && jobs.length === 0 && (
          <div className="empty">
            <strong>Noch keine Jobs</strong>
            <span>
              {canEditHttp(profile) ? 'Lege den ersten Job an, der regelmäßig eine Adresse aufruft.' : 'Sobald jemand einen Job anlegt, erscheint er hier.'}
            </span>
            {canEditHttp(profile) && (
              <button type="button" className="btn btn--solid" onClick={() => navigate('jobs/neu')}>
                Neuen Job anlegen
              </button>
            )}
          </div>
        )}
        {data !== null && jobs.length > 0 && shown.length === 0 && <p className="card__lead">Kein Job passt zu den Filtern.</p>}
        {shown.length > 0 && (
          <div className="table-wrap">
            <table className="table table--stack">
              <caption className="visually-hidden">Jobs ({shown.length})</caption>
              <thead>
                <tr>
                  <th scope="col">Job</th>
                  <th scope="col">Art</th>
                  <th scope="col">Zeitplan</th>
                  <th scope="col">Letzter Lauf</th>
                  <th scope="col">Nächster Lauf</th>
                  <th scope="col">Besitzer</th>
                </tr>
              </thead>
              <tbody>
                {shown.map((job) => {
                  const state = stateOf(job);
                  const preset = presetName(job.cron);
                  return (
                    <tr key={job.id}>
                      <td data-label="Job">
                        <div className="cell">
                        <a className="joblink" href={hrefOf('jobs/' + String(job.id))}>
                          {job.name}
                        </a>
                        <span className="joblink__sub">
                          {job.http !== null ? hostOf(job.http.target) : '–'}
                          {job.category !== null ? ' · ' + job.category.name : ''}
                        </span>
                        {state !== 'scheduled' && <span className={'jobstate jobstate--' + state}>{STATE_LABEL[state]}</span>}
                        </div>
                      </td>
                      <td data-label="Art">{job.type === 'http' ? 'HTTP' : 'Shell'}</td>
                      <td data-label="Zeitplan">
                        <div className="cell">
                          {preset !== null ? <span>{preset}</span> : null}
                          <code className="cron">{job.cron}</code>
                        </div>
                      </td>
                      <td data-label="Letzter Lauf">
                        {job.last_run === null ? (
                          <span className="muted">noch keiner</span>
                        ) : (
                          <div className="cell">
                            <RunBadge status={job.last_run.status} />
                            <span className="muted block">{formatShort(job.last_run.finished_at)}</span>
                          </div>
                        )}
                      </td>
                      <td data-label="Nächster Lauf">{job.is_enabled ? formatShort(job.next_run_at) : '–'}</td>
                      <td data-label="Besitzer">{job.owner?.display_name ?? '–'}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  );
}
