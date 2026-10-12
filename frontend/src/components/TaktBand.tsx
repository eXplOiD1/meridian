/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { hrefOf } from '../lib/useHashRoute';
import type { Overview } from '../types';
import { t } from '../i18n';

const ROW_HEIGHT = 18;
const AXIS_HOURS = [0, 3, 6, 9, 12, 15, 18, 21, 24];

/**
 * „Heute im Takt“: je Job eine Zeile über den Tag, ein Strich je 5-Minuten-Abschnitt mit Läufen (grün), Fehlern (rot)
 * oder noch geplanten Läufen (hell). Gezeichnet als SVG (nur Attribute, keine Inline-Stile); die Werte kommen vom
 * Server, Namen sind schon maskiert und werden als Text ausgegeben.
 */
export function TaktBand({ data, from }: { data: Overview; from: Date }) {
  const { buckets, now_bucket: nowBucket, bucket_seconds: size } = data.window;
  const axis = AXIS_HOURS.map((hour) => {
    const at = new Date(from.getFullYear(), from.getMonth(), from.getDate(), hour, 0, 0);
    const position = (at.getTime() - from.getTime()) / 1000 / size;
    return { hour, position: Math.max(0, Math.min(buckets, position)) };
  });
  const pad = (n: number): string => String(n).padStart(2, '0');

  return (
    <section className="card takt" aria-label={t('Heute im Takt')}>
      <div className="takt__head">
        <h2>{t('Heute im Takt')}</h2>
        <span className="takt__legend">{t('Jeder Strich ist ein Zeitabschnitt mit Läufen. Rot heißt: fehlgeschlagen. Hell: noch geplant.')}</span>
      </div>
      {data.jobs.length === 0 && <p className="card__lead">{t('Heute keine Läufe und keine geplanten Läufe.')}</p>}
      {data.jobs.length > 0 && (
        <div className="takt__grid">
          <div />
          <svg className="takt__axis" viewBox={'0 0 ' + String(buckets) + ' 10'} preserveAspectRatio="none" aria-hidden="true" height="12">
            {axis.map((tick) => (
              <rect key={tick.hour} x={tick.position} y={6} width={0.5} height={4} className="takt__tick" />
            ))}
          </svg>
          <div />
          <div className="takt__axislabels" aria-hidden="true">
            {axis.map((tick) => (
              <span key={tick.hour} className="takt__axislabel">
                {pad(tick.hour % 24)}:00
              </span>
            ))}
          </div>
          {data.jobs.map((job) => (
            <TaktRow key={job.id} job={job} buckets={buckets} nowBucket={nowBucket} />
          ))}
        </div>
      )}
      {data.jobs.length > 0 && nowBucket >= 0 && nowBucket < buckets && (
        <p className="takt__now">
          {t('Jetzt:')} <strong>{pad(new Date().getHours())}:{pad(new Date().getMinutes())}</strong>
        </p>
      )}
    </section>
  );
}

function TaktRow({ job, buckets, nowBucket }: { job: Overview['jobs'][number]; buckets: number; nowBucket: number }) {
  const summary = job.buckets.reduce((sum, b) => sum + b[1] + b[2], 0);
  const failed = job.buckets.reduce((sum, b) => sum + b[2], 0);
  return (
    <>
      <a className="takt__label" href={hrefOf('jobs/' + String(job.id))}>
        {job.name}
      </a>
      <svg
        className="takt__row"
        viewBox={'0 0 ' + String(buckets) + ' ' + String(ROW_HEIGHT)}
        preserveAspectRatio="none"
        role="img"
        aria-label={t('{name}: {runs} Läufe heute, {failed} fehlgeschlagen, {planned} Abschnitte noch geplant', { name: job.name, runs: summary, failed, planned: job.planned.length })}
      >
        <rect x={0} y={ROW_HEIGHT - 3} width={buckets} height={3} className="takt__track" />
        {job.planned.map((index) => (
          <rect key={'p' + String(index)} x={index} y={2} width={0.6} height={ROW_HEIGHT - 6} className="takt__planned" />
        ))}
        {job.buckets.map(([index, ok, bad]) => (
          <rect key={'r' + String(index)} x={index} y={2} width={0.8} height={ROW_HEIGHT - 6} className={bad > 0 ? 'takt__bad' : ok > 0 ? 'takt__ok' : 'takt__planned'} />
        ))}
        {nowBucket >= 0 && nowBucket < buckets && <rect x={nowBucket} y={0} width={0.8} height={ROW_HEIGHT} className="takt__now-line" />}
      </svg>
    </>
  );
}
