/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { useEffect, useRef, useState } from 'react';
import { hrefOf } from '../lib/useHashRoute';
import type { Overview } from '../types';
import { t } from '../i18n';

const ROW_HEIGHT = 18;

/**
 * „Heute im Takt“: je Job eine Zeile über den Tag, ein Strich je 5-Minuten-Abschnitt mit Läufen (grün), Fehlern (rot)
 * oder noch geplanten Läufen (hell). Gezeichnet als SVG (nur Attribute, keine Inline-Stile); die Werte kommen vom
 * Server, Namen sind schon maskiert und werden als Text ausgegeben.
 */
export function TaktBand({ data, from, title = 'Heute im Takt' }: { data: Overview; from: Date; title?: string }) {
  const rows = [...data.jobs, ...(data.monitors ?? [])];
  const { buckets, now_bucket: nowBucket, bucket_seconds: size } = data.window;
  const labelsRef = useRef<SVGSVGElement>(null);
  const [labelWidth, setLabelWidth] = useState(1000);
  useEffect(() => {
    const node = labelsRef.current;
    if (!node) return;
    const observer = new ResizeObserver(() => setLabelWidth(node.getBoundingClientRect().width || 1000));
    observer.observe(node);
    return () => observer.disconnect();
  }, [rows.length > 0]);
  const end = new Date(data.window.to);
  const duration = end.getTime() - from.getTime();
  const daily = duration <= 26 * 3600000;
  const yearly = duration > 32 * 86400000;
  const axis: Array<{ position: number; label: string; major: boolean }> = [];
  const cursor = new Date(from);
  let index = 0;
  const addTick = (at: Date, last = false) => {
    const hour = at.getHours();
    const major = last || index === 0 || (daily ? hour % 3 === 0 : yearly || duration <= 8 * 86400000 || index % 3 === 0);
    const label = daily ? `${String(hour).padStart(2, '0')}:${String(at.getMinutes()).padStart(2, '0')}` : at.toLocaleDateString(document.documentElement.lang || undefined, yearly ? { month: 'short', ...(last ? { year: 'numeric' } : {}) } : { day: '2-digit', month: '2-digit' });
    axis.push({ position: (at.getTime() - from.getTime()) / (size * 1000), label: major ? label : '', major });
  };
  while (cursor < end && index < 400) {
    addTick(cursor);
    if (daily) cursor.setTime(cursor.getTime() + 3600000);
    else if (yearly) { cursor.setDate(1); cursor.setMonth(cursor.getMonth() + 1); }
    else { cursor.setHours(0, 0, 0, 0); cursor.setDate(cursor.getDate() + 1); }
    index++;
  }
  addTick(end, true);
  const visibleLabels = new Set<number>();
  const lastTick = axis[axis.length - 1];
  const endLabelStart = lastTick ? lastTick.position / buckets * labelWidth - lastTick.label.length * 7 : labelWidth;
  let previousRight = -Infinity;
  for (const [index, tick] of axis.entries()) {
    if (!tick.label) continue;
    const x = tick.position / buckets * labelWidth;
    const width = tick.label.length * 7;
    const left = index === 0 ? x : x - width / 2;
    const right = index === 0 ? x + width : x + width / 2;
    if (index === 0 || index === axis.length - 1 || (left > previousRight + 8 && right < endLabelStart - 8)) {
      visibleLabels.add(tick.position);
      previousRight = right;
    }
  }
  const pad = (n: number): string => String(n).padStart(2, '0');

  return (
    <section className="card takt" aria-label={t(title)}>
      <div className="takt__head">
        <h2>{t(title)}</h2>
        <span className="takt__legend">{t('Jeder Strich zeigt Ereignisse in einem Zeitabschnitt. Rot: Fehler. Hell: geplant. Abschnitt anklicken für Details.')}</span>
      </div>
      {rows.length === 0 && <p className="card__lead">{t('Keine Daten in diesem Zeitraum.')}</p>}
      {rows.length > 0 && (
        <div className="takt__grid">
          <div />
          <svg className="takt__axis" viewBox={'0 0 ' + String(buckets) + ' 10'} preserveAspectRatio="none" aria-hidden="true" height="12">
            {axis.map((tick) => (
              <line key={tick.position} x1={Math.min(buckets - 0.4, tick.position)} x2={Math.min(buckets - 0.4, tick.position)} y1={tick.major ? 0 : 5} y2={10} vectorEffect="non-scaling-stroke" className="takt__tick" />
            ))}
          </svg>
          <div />
          <svg ref={labelsRef} className="takt__axislabels" viewBox={`0 0 ${labelWidth} 20`} preserveAspectRatio="none" aria-hidden="true" height="20">
            {axis.map((tick, index) => (
              <text key={tick.position} x={tick.position / buckets * labelWidth} y={13} textAnchor={index === axis.length - 1 ? 'end' : index === 0 ? 'start' : 'middle'} className="takt__axislabel">{visibleLabels.has(tick.position) ? tick.label : ''}</text>
            ))}
          </svg>
          {rows.map((job) => (
            <TaktRow key={(job.kind ?? 'job') + job.id} job={job} buckets={buckets} nowBucket={nowBucket} from={from} size={size} dataEnd={data.window.to} />
          ))}
        </div>
      )}
      {rows.length > 0 && nowBucket >= 0 && nowBucket < buckets && (
        <p className="takt__now">
          {t('Jetzt:')} <strong>{pad(new Date().getHours())}:{pad(new Date().getMinutes())}</strong>
        </p>
      )}
    </section>
  );
}

function TaktRow({ job, buckets, nowBucket, from, size, dataEnd }: { job: Overview['jobs'][number]; buckets: number; nowBucket: number; from: Date; size: number; dataEnd: string }) {
  const monitor = job.kind === 'monitor';
  const summary = job.buckets.reduce((sum, b) => sum + b[1] + b[2], 0);
  const failed = job.buckets.reduce((sum, b) => sum + b[2], 0);
  return (
    <>
      <a className="takt__label" href={hrefOf(monitor ? 'verlauf?source=monitors&monitor_id=' + job.id : 'jobs/' + String(job.id))}>
        <span className="takt__name">{job.name}</span>{monitor && <span className="badge">{t('Überwachung')}</span>} {!job.enabled && <span className="badge badge--muted">{t('Deaktiviert')}</span>}
      </a>
      <svg
        className="takt__row"
        viewBox={'0 0 ' + String(buckets) + ' ' + String(ROW_HEIGHT)}
        preserveAspectRatio="none"
        role="img"
        aria-label={t('{name}: {runs} Ereignisse, {failed} fehlgeschlagen', { name: job.name, runs: summary, failed })}
      >
        <rect x={0} y={ROW_HEIGHT - 3} width={buckets} height={3} className="takt__track" />
        {job.planned.map((index) => (
          <rect key={'p' + String(index)} x={index} y={2} width={0.6} height={ROW_HEIGHT - 6} className="takt__planned" />
        ))}
        {job.buckets.map(([index, ok, bad]) => {
          const start = new Date(from.getTime() + index * size * 1000);
          const end = new Date(Math.min(start.getTime() + size * 1000, new Date(dataEnd).getTime()));
          const query = new URLSearchParams({ [monitor ? 'monitor_id' : 'job_id']: String(job.id), from: start.toISOString().replace(/\.\d{3}Z$/, 'Z'), to: end.toISOString().replace(/\.\d{3}Z$/, 'Z'), source: monitor ? 'monitors' : 'jobs' });
          const label = t('{name}: {runs} Ereignisse, {failed} fehlgeschlagen', { name: job.name, runs: ok + bad, failed: bad }) + ' · ' + start.toLocaleString();
          return <a key={'r' + index} href={hrefOf('verlauf?' + query.toString())} aria-label={label}><title>{label}</title><rect x={index} y={2} width={0.8} height={ROW_HEIGHT - 6} className={bad > 0 ? 'takt__bad' : ok > 0 ? 'takt__ok' : 'takt__planned'} /></a>;
        })}
        {nowBucket >= 0 && nowBucket < buckets && <rect x={nowBucket} y={0} width={0.8} height={ROW_HEIGHT} className="takt__now-line" />}
      </svg>
    </>
  );
}
