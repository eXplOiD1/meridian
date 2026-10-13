/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary */
import { useEffect, useState } from 'react';
import { History } from './History';
import { Alert } from '../components/Alert';
import { TaktBand } from '../components/TaktBand';
import { request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatTime } from '../lib/format';
import { t } from '../i18n';
import type { Overview, Profile } from '../types';

const iso = (date: Date) => date.toISOString().replace(/\.\d{3}Z$/, 'Z');
const dayValue = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const localValue = (date: Date) => `${dayValue(date)}T${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
function initialValue(name: string) {
  const value = new URLSearchParams(location.hash.split('?')[1] ?? '').get(name);
  if (!value || Number.isNaN(new Date(value).getTime())) return '';
  return localValue(new Date(value));
}

export function HistoryExplorer({ profile }: { profile: Profile }) {
  const params = new URLSearchParams(location.hash.split('?')[1] ?? '');
  const [fromInput, setFrom] = useState(() => initialValue('from'));
  const [toInput, setTo] = useState(() => initialValue('to'));
  const [source, setSource] = useState(params.get('source') === 'monitors' ? 'monitors' : params.get('source') === 'jobs' ? 'jobs' : 'all');
  const [jobId, setJob] = useState(params.get('job_id') ?? '');
  const [monitorId, setMonitor] = useState(params.get('monitor_id') ?? '');
  const [preset, setPreset] = useState(fromInput ? 'custom' : 'all');
  const [calendarValue, setCalendarValue] = useState('');
  const [day, setDay] = useState(() => dayValue(fromInput ? new Date(fromInput) : new Date()));
  const invalid = Boolean(fromInput && toInput && new Date(fromInput) >= new Date(toInput));
  const from = fromInput && !Number.isNaN(new Date(fromInput).getTime()) ? iso(new Date(fromInput)) : '';
  const to = toInput && !Number.isNaN(new Date(toInput).getTime()) ? iso(new Date(toInput)) : '';
  function choose(value: string) {
    setPreset(value);
    if (value === 'all') { setFrom(''); setTo(''); return; }
    if (value === 'custom' || value.startsWith('pick-')) { setCalendarValue(''); return; }
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    if (value === 'yesterday') start.setDate(start.getDate() - 1);
    if (value === 'week') start.setDate(start.getDate() - (start.getDay() + 6) % 7);
    if (value === 'month') start.setDate(1);
    const end = new Date(start);
    if (value === 'month') end.setMonth(end.getMonth() + 1);
    else end.setDate(end.getDate() + (value === 'week' ? 7 : 1));
    setFrom(localValue(start)); setTo(localValue(end)); setDay(dayValue(start));
  }
  function chooseCalendar(value: string) {
    setCalendarValue(value);
    let start: Date;
    let end: Date;
    if (preset === 'pick-week') {
      const match = /^(\d{4})-W(\d{2})$/.exec(value);
      if (!match || Number(match[2]) < 1 || Number(match[2]) > 53) return;
      start = new Date(Number(match[1]), 0, 4);
      start.setDate(start.getDate() - (start.getDay() + 6) % 7 + (Number(match[2]) - 1) * 7);
      end = new Date(start); end.setDate(end.getDate() + 7);
    } else if (preset === 'pick-month') {
      if (!/^\d{4}-\d{2}$/.test(value)) return;
      start = new Date(value + '-01T00:00:00'); end = new Date(start); end.setMonth(end.getMonth() + 1);
    } else {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return;
      start = new Date(value + 'T00:00:00'); end = new Date(start); end.setDate(end.getDate() + 1);
    }
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return;
    setFrom(localValue(start)); setTo(localValue(end)); setDay(dayValue(start));
  }
  return <>
    <section className="card"><h2>{t('Zeitraum und Ereignisse')}</h2><div className="row history-filters">
      <label className="field"><span className="field__label">{t('Zeitraum')}</span><select className="input" value={preset} onChange={e => choose(e.target.value)}>
        <option value="all">{t('Alle verfügbaren Daten')}</option><option value="today">{t('Heute')}</option><option value="yesterday">{t('Gestern')}</option><option value="week">{t('Diese Woche')}</option><option value="month">{t('Dieser Monat')}</option><option value="pick-day">{t('Tag wählen')}</option><option value="pick-week">{t('Woche wählen')}</option><option value="pick-month">{t('Monat wählen')}</option><option value="custom">{t('Eigener Zeitraum')}</option>
      </select></label>
      {preset.startsWith('pick-') && <label className="field"><span className="field__label">{t('Kalenderauswahl')}</span><input className="input" type={preset === 'pick-week' ? 'week' : preset === 'pick-month' ? 'month' : 'date'} value={calendarValue} onChange={e => chooseCalendar(e.target.value)} /></label>}
      <label className="field"><span className="field__label">{t('Von')}</span><input className="input" type="datetime-local" value={fromInput} onChange={e => { setFrom(e.target.value); setPreset('custom'); }} /></label>
      <label className="field"><span className="field__label">{t('Bis (ausschließlich)')}</span><input className="input" type="datetime-local" value={toInput} onChange={e => { setTo(e.target.value); setPreset('custom'); }} /></label>
      <label className="field"><span className="field__label">{t('Ereignisse')}</span><select className="input" value={source} onChange={e => setSource(e.target.value)}><option value="all">{t('Jobs und Überwachungen')}</option><option value="jobs">{t('Jobs')}</option><option value="monitors">{t('Überwachungen')}</option></select></label>
    </div>{(jobId || monitorId) && <div className="row"><span>{t('Auf den angeklickten Eintrag gefiltert.')}</span><button className="btn btn--ghost" onClick={() => { setJob(''); setMonitor(''); }}>{t('Filter entfernen')}</button></div>}
      {invalid && <Alert tone="err">{t('Das Ende muss nach dem Beginn liegen.')}</Alert>}
      <p className="hint">{t('Überwachungsereignisse werden ab dieser Version für 90 Tage gespeichert. Ältere Zeiträume können ohne Daten sein. Versand bestätigt die Annahme durch den Benachrichtigungsdienst, nicht die Anzeige auf dem Gerät.')}</p>
    </section>
    <label className="field field--inline"><span className="field__label">{t('Tag in der Tagesansicht')}</span><input type="date" className="input" value={day} max={dayValue(new Date())} onChange={e => { if (e.target.value) { setDay(e.target.value); const start = new Date(e.target.value + 'T00:00:00'); const end = new Date(start); end.setDate(end.getDate() + 1); setFrom(localValue(start)); setTo(localValue(end)); setPreset('custom'); } }} /></label>
    <DayHistory key={day} day={day} />
    {!invalid && source !== 'monitors' && <History key={`jobs:${from}:${to}:${jobId}`} profile={profile} from={from} to={to} jobId={jobId} />}
    {!invalid && source !== 'jobs' && <MonitorHistory key={`monitors:${from}:${to}:${monitorId}`} from={from} to={to} monitorId={monitorId} />}
  </>;
}

function DayHistory({ day }: { day: string }) {
  const [data, setData] = useState<Overview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const start = new Date(day + 'T00:00:00');
  useEffect(() => {
    let cancelled = false;
    async function load() {
      const from = new Date(day + 'T00:00:00');
      const to = new Date(from); to.setDate(to.getDate() + 1);
      try { const next = await request<Overview>('GET', '/api/overview?' + new URLSearchParams({ from: iso(from), to: iso(to) })); if (!cancelled) { setData(next); setError(null); } }
      catch (caught) { if (!cancelled) setError(errorMessage(caught)); }
    }
    void load(); const timer = window.setInterval(() => void load(), 60000);
    return () => { cancelled = true; clearInterval(timer); };
  }, [day]);
  if (error) return <Alert tone="err">{error}</Alert>;
  return data ? <TaktBand data={data} from={start} title="Tagesverlauf" /> : <p>{t('Lädt …')}</p>;
}

interface MonitorEvent { id: number; monitor_id: number; name: string; category: string | null; occurred_at: string; kind: string; state: string; ok: boolean | null; latency_ms: number | null; note: string; notifications: { pending: number; sent: number; failed: number } }
function MonitorHistory({ from, to, monitorId }: { from: string; to: string; monitorId: string }) {
  const [events, setEvents] = useState<MonitorEvent[]>([]);
  const [next, setNext] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [selected, setSelected] = useState<number | null>(null);
  async function load(before?: number) {
    setBusy(true);
    const query = new URLSearchParams(); if (from) query.set('from', from); if (to) query.set('to', to); if (monitorId) query.set('monitor_id', monitorId); if (before) query.set('before_id', String(before));
    try { const data = await request<{ events: MonitorEvent[]; next_before_id: number | null }>('GET', '/api/monitors/history?' + query); setEvents(old => before ? [...old, ...data.events] : data.events); setNext(data.next_before_id); setError(null); }
    catch (caught) { setError(errorMessage(caught)); } finally { setBusy(false); }
  }
  useEffect(() => { void load(); }, [from, to, monitorId]);
  return <section className="card"><h2>{t('Verlauf der Überwachungen')}</h2>{error && <Alert tone="err">{error}</Alert>}
    <div className="table-wrap"><table className="table"><thead><tr><th>{t('Zeit')}</th><th>{t('Überwachung')}</th><th>{t('Ereignis')}</th><th>{t('Ergebnis')}</th><th>{t('Benachrichtigungen')}</th></tr></thead><tbody>
      {events.map(event => <tr key={event.id}><td><button className="linkbtn" aria-expanded={selected === event.id} onClick={() => setSelected(selected === event.id ? null : event.id)}>{formatTime(event.occurred_at)}</button></td><td>{event.name}<div className="hint">{event.category}</div></td><td>{t(event.kind === 'alarm' ? 'Alarm' : event.kind === 'alarm' ? 'Alarm' : event.kind === 'alarm' ? 'Alarm' : event.kind === 'alarm' ? 'Alarm' : event.kind === 'check' ? 'Prüfung' : event.kind === 'ping' ? 'Lebenszeichen' : 'Zustandswechsel')}</td><td>{t(event.ok === false ? 'Prüfung fehlgeschlagen' : event.ok === true ? 'Erreichbar' : event.state === 'down' ? 'Ausfall erkannt' : event.state === 'paused' ? 'Deaktiviert' : event.state === 'up' ? 'Wieder erreichbar' : 'Wartet auf Rückmeldung')}{event.latency_ms !== null && ` · ${event.latency_ms} ms`}{selected === event.id && <p>{event.note || t('Keine weitere Meldung.')}</p>}</td><td>{(event.kind === 'state' || event.kind === 'alarm') ? <><div>{t('Angenommen')}: {event.notifications.sent}</div><div>{t('Wartet')}: {event.notifications.pending}</div><div>{t('Versand fehlgeschlagen')}: {event.notifications.failed}</div></> : '—'}</td></tr>)}
    </tbody></table></div>{!busy && events.length === 0 && <p>{t('Keine Daten in diesem Zeitraum.')}</p>}{busy && <p>{t('Lädt …')}</p>}{next !== null && <button className="btn btn--ghost" disabled={busy} onClick={() => void load(next)}>{t('Mehr laden')}</button>}
  </section>;
}
