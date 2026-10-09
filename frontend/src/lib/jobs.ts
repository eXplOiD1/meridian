import type { JobSummary, RunStatus, RunTrigger } from '../types';

/** Voreinstellungen für den Zeitplan: Name → Cron-Ausdruck. Ein eigener Ausdruck bleibt immer möglich. */
export const CRON_PRESETS: ReadonlyArray<{ label: string; cron: string }> = [
  { label: 'Jede Minute', cron: '* * * * *' },
  { label: 'Alle 5 Minuten', cron: '*/5 * * * *' },
  { label: 'Alle 15 Minuten', cron: '*/15 * * * *' },
  { label: 'Stündlich', cron: '0 * * * *' },
  { label: 'Täglich um 03:00', cron: '0 3 * * *' },
  { label: 'Wochentags um 08:00', cron: '0 8 * * 1-5' },
  { label: 'Wöchentlich (Mo 06:00)', cron: '0 6 * * 1' },
  { label: 'Monatlich (1., 04:00)', cron: '0 4 1 * *' },
];

export function presetName(cron: string): string | null {
  return CRON_PRESETS.find((preset) => preset.cron === cron.trim().replace(/\s+/g, ' '))?.label ?? null;
}

/** Der Server liefert bei http.display_host = hidden „https://••••“: Host und Port sind verborgen. */
export function isHostHidden(target: string): boolean {
  return target.includes('•');
}

/** Anzeige des Ziels als reiner Text: Host oder – bei verborgenem Host – das Schema mit Platzhalter. */
export function targetLabel(target: string): string {
  return isHostHidden(target) ? target : hostOf(target);
}

/** Nur der Host eines Ziels („https://api.example.org:8443“ → „api.example.org“), nie Pfad oder Parameter. */
export function hostOf(target: string): string {
  try {
    return new URL(target).hostname;
  } catch {
    return target.replace(/^[a-z]+:\/\//i, '').replace(/[:/].*$/, '');
  }
}

export type JobState = 'running' | 'disabled' | 'failed' | 'scheduled';

export function stateOf(job: JobSummary): JobState {
  if (job.running) {
    return 'running';
  }
  if (!job.is_enabled) {
    return 'disabled';
  }
  const last = job.last_run?.status;
  if (last === 'failed' || last === 'timeout' || last === 'aborted') {
    return 'failed';
  }
  return 'scheduled';
}

export const STATE_LABEL: Record<JobState, string> = {
  running: 'läuft',
  disabled: 'deaktiviert',
  failed: 'letzter Lauf fehlgeschlagen',
  scheduled: 'geplant',
};

export const RUN_STATUS: Record<RunStatus, { label: string; tone: 'ok' | 'warn' | 'err' | 'info' }> = {
  queued: { label: 'wartet', tone: 'info' },
  running: { label: 'läuft', tone: 'warn' },
  ok: { label: 'erfolgreich', tone: 'ok' },
  failed: { label: 'fehlgeschlagen', tone: 'err' },
  timeout: { label: 'Zeitlimit überschritten', tone: 'err' },
  aborted: { label: 'abgebrochen', tone: 'err' },
  skipped: { label: 'übersprungen', tone: 'warn' },
};

export const TRIGGER_LABEL: Record<RunTrigger, string> = {
  schedule: 'Zeitplan',
  manual: 'manuell',
  retry: 'Wiederholung',
  test: 'Testlauf',
};

export function isFinished(status: RunStatus): boolean {
  return status !== 'queued' && status !== 'running';
}

const OVERLAP_LABEL = { skip: 'überspringen', parallel: 'parallel', queue: 'einreihen' } as const;
export function overlapLabel(policy: keyof typeof OVERLAP_LABEL): string {
  return OVERLAP_LABEL[policy];
}

export function formatDuration(ms: number | null): string {
  if (ms === null) {
    return '–';
  }
  return ms < 1000 ? String(ms) + ' ms' : (ms / 1000).toFixed(ms < 10000 ? 1 : 0).replace('.', ',') + ' s';
}
