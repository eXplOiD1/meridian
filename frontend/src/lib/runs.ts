import { request } from './api';

/** Manuellen Lauf oder Testlauf einreihen (202 {run_id}); das Ergebnis wird über /api/runs/{id} abgefragt. */
export async function triggerRun(kind: 'manual' | 'test', jobId: string, csrf: string): Promise<number> {
  const data = await request<{ run_id?: unknown; run?: { id?: unknown } }>('POST', '/api/jobs/' + jobId + (kind === 'manual' ? '/run' : '/test'), { csrf });
  const id = data.run_id ?? data.run?.id;
  if (typeof id !== 'number') {
    throw new Error('Unerwartete Antwort des Servers.');
  }
  return id;
}
