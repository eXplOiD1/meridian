import { RUN_STATUS } from '../lib/jobs';
import type { RunStatus } from '../types';

/** Lauf-Status als Punkt plus Text (Farbe allein trägt nie die Bedeutung). */
export function RunBadge({ status }: { status: RunStatus }) {
  const info = RUN_STATUS[status];
  return (
    <span className="runstate">
      <span className={'dot dot--' + (info.tone === 'ok' ? 'ok' : info.tone) + (status === 'running' ? ' pulse dot--now' : '')} aria-hidden="true" />
      {info.label}
    </span>
  );
}
