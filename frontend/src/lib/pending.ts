/**
 * Übergabe „soeben ausgelöster Lauf“ vom Editor („Speichern und testen“) an die Job-Ansicht.
 * Nur eine Zahl im Arbeitsspeicher, nichts im Browser-Speicher und nichts in der URL.
 */
let pending: { jobId: string; runId: number; kind: 'manual' | 'test' } | null = null;

export function setPendingRun(jobId: string, runId: number, kind: 'manual' | 'test'): void {
  pending = { jobId, runId, kind };
}

export function takePendingRun(jobId: string): { runId: number; kind: 'manual' | 'test' } | null {
  if (pending === null || pending.jobId !== jobId) {
    return null;
  }
  const value = { runId: pending.runId, kind: pending.kind };
  pending = null;
  return value;
}
