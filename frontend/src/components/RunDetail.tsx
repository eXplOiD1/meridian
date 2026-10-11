import { RunBadge } from './RunBadge';
import { formatTime } from '../lib/format';
import { formatDuration, TRIGGER_LABEL } from '../lib/jobs';
import type { Run } from '../types';

/** Lauf-Ausgabe immer als Text: React setzt sie als Textknoten, nie als HTML. */
export function RunDetail({ run, heading }: { run: Run; heading: string }) {
  const truncated = run.output != null && run.output.includes('[… ausgelassen');
  return (
    <div className="rundetail" aria-label={heading}>
      <div className="rundetail__head">
        <RunBadge status={run.status} />
        <span className="muted">
          {TRIGGER_LABEL[run.trigger]} · Versuch {run.attempt}
          {run.http_status !== null ? ' · HTTP ' + String(run.http_status) : ''}
          {run.exit_code != null ? ' · Exit-Code ' + String(run.exit_code) : ''} · {formatDuration(run.duration_ms)}
        </span>
      </div>
      <div className="muted">
        Gestartet: {run.started_at === null ? '–' : formatTime(run.started_at)}
        {run.started_by !== null ? ' von ' + run.started_by.display_name : ''}
      </div>
      {run.cancelled_by != null && <p className="rundetail__note">Abgebrochen von {run.cancelled_by.display_name}.</p>}
      {run.cancelled_by == null && run.cancel_requested_at != null && <p className="rundetail__note">Abbruch angefordert.</p>}
      {run.note !== null && run.note !== '' && <p className="rundetail__note">{run.note}</p>}
      <div className="field__label">Ausgabe</div>
      {run.output === undefined || run.output === null || run.output === '' ? <p className="hint">Keine Ausgabe gespeichert.</p> : <pre className="runout">{run.output}</pre>}
      {truncated && <p className="hint">Die Ausgabe war zu lang: gezeigt werden Anfang und Ende, die Mitte wurde ausgelassen.</p>}
    </div>
  );
}
