import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { Alert } from './Alert';
import { Confirm } from './Confirm';
import { RunBadge } from './RunBadge';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatDuration, isFinished } from '../lib/jobs';
import type { LogChunk, LogPage, Run, RunStatus } from '../types';

const POLL_MS = 2000;
const POLL_LIMIT = 200;
/** Anzeigegrenze im Browser (Server begrenzt je Lauf auf 1 MiB); darüber fallen die ältesten Stücke weg. */
const MAX_CHARS = 2 * 1024 * 1024;
/** So viele Fehler ohne erfolgreiche Verbindung, dann Rückfall auf Abfragen. */
const MAX_SSE_ERRORS = 3;
const NEAR_BOTTOM_PX = 24;

type Mode = 'connecting' | 'live' | 'poll';

interface Props {
  runId: number;
  csrf: string;
  /** Recht „Jobs ausführen“ für diese Kategorie; nur Bedienkomfort, der Server prüft selbst. */
  canCancel: boolean;
  /** Der Lauf ist abgeschlossen: der vollständige, gespeicherte Lauf (mit Ausgabe). */
  onFinished: (run: Run) => void;
}

interface Piece {
  key: string;
  stream: LogChunk['stream'];
  text: string;
  tag: string | null;
}

/**
 * Zerlegt die Stücke in darstellbare Teile: stderr- und Systemzeilen bekommen am Zeilenanfang ein Präfix
 * (zusätzlich zur Farbe), und ein Streamwechsel mitten in einer Zeile beginnt eine neue Zeile.
 */
function toPieces(chunks: LogChunk[]): Piece[] {
  const out: Piece[] = [];
  let atLineStart = true;
  let previous: LogChunk['stream'] | null = null;
  for (const chunk of chunks) {
    let text = chunk.text;
    if (!atLineStart && previous !== null && previous !== chunk.stream && text !== '') {
      out.push({ key: String(chunk.seq) + '-nl', stream: previous, text: '\n', tag: null });
      atLineStart = true;
    }
    let index = 0;
    while (text !== '') {
      const newline = text.indexOf('\n');
      const line = newline === -1 ? text : text.slice(0, newline + 1);
      text = newline === -1 ? '' : text.slice(newline + 1);
      const tag = atLineStart && chunk.stream === 'err' ? 'stderr ' : atLineStart && chunk.stream === 'sys' ? 'meridian ' : null;
      out.push({ key: String(chunk.seq) + '-' + String(index), stream: chunk.stream, text: line, tag });
      index += 1;
      atLineStart = line.endsWith('\n');
    }
    previous = chunk.stream;
  }
  return out;
}

function prefersReducedMotion(): boolean {
  try {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  } catch {
    return false;
  }
}

/**
 * Live-Log eines Laufs. Ausgabe nur als Text (React-Textknoten, nie HTML). Zuerst EventSource (gleiche Herkunft,
 * Sitzung per Cookie); bei Fehler, 429 oder Blockade Rückfall auf Abfragen alle 2 s über /log.
 */
export function RunLog({ runId, csrf, canCancel, onFinished }: Props) {
  const [chunks, setChunks] = useState<LogChunk[]>([]);
  const [trimmed, setTrimmed] = useState(false);
  const [status, setStatus] = useState<RunStatus>('queued');
  const [mode, setMode] = useState<Mode>('connecting');
  const [run, setRun] = useState<Run | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [cancelError, setCancelError] = useState<string | null>(null);
  const [cancelBusy, setCancelBusy] = useState(false);
  const [cancelRequested, setCancelRequested] = useState(false);
  const [follow, setFollow] = useState(() => !prefersReducedMotion());
  const [now, setNow] = useState(() => Date.now());
  const preRef = useRef<HTMLPreElement | null>(null);
  const finishedRef = useRef(false);
  const onFinishedRef = useRef(onFinished);
  onFinishedRef.current = onFinished;

  const done = isFinished(status);

  useEffect(() => {
    let cancelled = false;
    let source: EventSource | null = null;
    let timer = 0;
    let lastSeq = 0;
    let errors = 0;
    finishedRef.current = false;
    setChunks([]);
    setTrimmed(false);
    setStatus('queued');
    setMode('connecting');
    setRun(null);
    setProblem(null);
    setCancelRequested(false);

    function append(incoming: LogChunk[]): void {
      const fresh = incoming.filter((chunk) => chunk.seq > lastSeq);
      if (fresh.length === 0) {
        return;
      }
      lastSeq = fresh[fresh.length - 1]?.seq ?? lastSeq;
      setChunks((current) => {
        let next = [...current, ...fresh];
        let size = next.reduce((sum, chunk) => sum + chunk.text.length, 0);
        if (size > MAX_CHARS) {
          while (size > MAX_CHARS && next.length > 1) {
            size -= next[0]?.text.length ?? 0;
            next = next.slice(1);
          }
          setTrimmed(true);
        }
        return next;
      });
    }

    async function finish(): Promise<void> {
      if (finishedRef.current || cancelled) {
        return;
      }
      finishedRef.current = true;
      source?.close();
      window.clearTimeout(timer);
      try {
        const data = await request<{ run: Run }>('GET', '/api/runs/' + String(runId));
        if (cancelled) {
          return;
        }
        setRun(data.run);
        setStatus(data.run.status);
        if (data.run.cancel_requested_at != null) {
          setCancelRequested(true);
        }
        if (isFinished(data.run.status)) {
          onFinishedRef.current(data.run);
        } else {
          // Der Strom endete, der Lauf aber nicht (z. B. wartet noch): Abfragen übernehmen.
          finishedRef.current = false;
          startPolling();
        }
      } catch (caught) {
        if (!cancelled) {
          setProblem(errorMessage(caught));
        }
      }
    }

    async function poll(): Promise<void> {
      if (cancelled || finishedRef.current) {
        return;
      }
      let more = false;
      try {
        const page = await request<LogPage>('GET', '/api/runs/' + String(runId) + '/log?after=' + String(lastSeq) + '&limit=' + String(POLL_LIMIT));
        if (cancelled) {
          return;
        }
        append(page.chunks);
        setStatus(page.status);
        setProblem(null);
        more = page.chunks.length >= POLL_LIMIT;
        if (page.done && !more) {
          await finish();
          return;
        }
      } catch (caught) {
        if (cancelled) {
          return;
        }
        if (caught instanceof ApiError && (caught.status === 403 || caught.status === 404)) {
          setProblem(caught.message);
          return;
        }
        setProblem(errorMessage(caught));
      }
      timer = window.setTimeout(() => void poll(), more ? 0 : POLL_MS);
    }

    function startPolling(): void {
      source?.close();
      source = null;
      setMode('poll');
      void poll();
    }

    function openStream(): void {
      let es: EventSource;
      try {
        es = new EventSource('/api/runs/' + String(runId) + '/live' + (lastSeq > 0 ? '?after=' + String(lastSeq) : ''));
      } catch {
        startPolling();
        return;
      }
      source = es;
      es.onopen = () => {
        errors = 0;
        setMode('live');
      };
      es.addEventListener('chunk', (event) => {
        try {
          const data: unknown = JSON.parse((event as MessageEvent<string>).data);
          const seq = Number.parseInt((event as MessageEvent<string>).lastEventId, 10);
          if (typeof data === 'object' && data !== null && Number.isFinite(seq)) {
            const record = data as { stream?: unknown; text?: unknown };
            const stream = record.stream === 'err' || record.stream === 'sys' ? record.stream : 'out';
            if (typeof record.text === 'string') {
              append([{ seq, stream, text: record.text }]);
            }
          }
        } catch {
          // Ein unlesbares Ereignis wird übersprungen; die Ausgabe holt der Abschluss nach.
        }
      });
      es.addEventListener('status', (event) => {
        try {
          const data = JSON.parse((event as MessageEvent<string>).data) as { status?: unknown };
          if (typeof data.status === 'string' && data.status in { queued: 1, running: 1, ok: 1, failed: 1, timeout: 1, aborted: 1, skipped: 1 }) {
            setStatus(data.status as RunStatus);
          }
        } catch {
          // ignorieren
        }
      });
      es.addEventListener('end', (event) => {
        let reason = 'finished';
        try {
          const data = JSON.parse((event as MessageEvent<string>).data) as { reason?: unknown };
          reason = typeof data.reason === 'string' ? data.reason : 'finished';
        } catch {
          reason = 'error';
        }
        es.close();
        if (reason === 'forbidden') {
          setProblem('Das Live-Log ist für dich nicht mehr freigegeben.');
        } else if (reason === 'gone') {
          setProblem('Der Lauf existiert nicht mehr.');
        } else if (reason === 'error') {
          startPolling();
        } else {
          void finish();
        }
      });
      // „reconnect“ (Server beendet nach 60 s): nicht schließen, der Browser verbindet mit Last-Event-ID neu.
      es.onerror = () => {
        if (cancelled) {
          return;
        }
        errors += 1;
        // CLOSED = der Server hat abgelehnt (429, 401, 403, Fehler); ein CSP-Verbot sieht genauso aus.
        if (es.readyState === EventSource.CLOSED || errors >= MAX_SSE_ERRORS) {
          startPolling();
        }
      };
    }

    openStream();
    return () => {
      cancelled = true;
      source?.close();
      window.clearTimeout(timer);
    };
  }, [runId]);

  // Startzeit und Abbruch-Vermerk des Laufs holen (beim Öffnen und sobald er läuft).
  const haveStart = run?.started_at != null;
  useEffect(() => {
    if (status === 'skipped' || haveStart && status !== 'queued') {
      return undefined;
    }
    let cancelled = false;
    request<{ run: Run }>('GET', '/api/runs/' + String(runId))
      .then((data) => {
        if (!cancelled) {
          setRun((current) => (current !== null && current.started_at != null && data.run.started_at == null ? current : data.run));
          if (data.run.cancel_requested_at != null) {
            setCancelRequested(true);
          }
        }
      })
      .catch(() => undefined);
    return () => {
      cancelled = true;
    };
  }, [runId, status, haveStart]);

  // Dauer mitlaufen lassen, solange der Lauf nicht fertig ist.
  useEffect(() => {
    if (done) {
      return undefined;
    }
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [done]);

  const pieces = useMemo(() => toPieces(chunks), [chunks]);

  // Mitlaufen: direkt ans Ende springen (kein animiertes Scrollen).
  useEffect(() => {
    const pre = preRef.current;
    if (follow && pre !== null) {
      pre.scrollTop = pre.scrollHeight;
    }
  }, [pieces, follow]);

  function onScroll(): void {
    const pre = preRef.current;
    if (pre === null) {
      return;
    }
    const atBottom = pre.scrollHeight - pre.scrollTop - pre.clientHeight <= NEAR_BOTTOM_PX;
    // Manuelles Hochscrollen schaltet das Mitlaufen aus; wieder unten angekommen, schaltet es sich ein.
    setFollow((current) => (current !== atBottom ? atBottom : current));
  }

  async function cancel(): Promise<void> {
    setCancelBusy(true);
    setCancelError(null);
    try {
      const data = await request<{ run: Run }>('POST', '/api/runs/' + String(runId) + '/cancel', { csrf });
      setCancelRequested(true);
      setRun(data.run);
      if (isFinished(data.run.status)) {
        setStatus(data.run.status);
        void request<{ run: Run }>('GET', '/api/runs/' + String(runId))
          .then((full) => onFinishedRef.current(full.run))
          .catch(() => undefined);
      }
    } catch (caught) {
      setCancelError(errorMessage(caught));
    } finally {
      setCancelBusy(false);
    }
  }

  const startedAt = run?.started_at != null ? Date.parse(run.started_at) : Number.NaN;
  const elapsed = Number.isFinite(startedAt) && status === 'running' ? formatDuration(Math.max(0, now - startedAt)) : null;
  const requested = cancelRequested || run?.cancel_requested_at != null;

  return (
    <div className="runlog" aria-label="Live-Ausgabe des Laufs">
      <div className="runlog__bar">
        <RunBadge status={status} />
        {!done && mode === 'live' && (
          <span className="livetag" data-testid="live-indicator">
            <span className="dot dot--now pulse" aria-hidden="true" />
            live
          </span>
        )}
        {!done && mode === 'poll' && <span className="muted">Abfrage alle 2 Sekunden</span>}
        {!done && mode === 'connecting' && <span className="muted">Verbindet …</span>}
        {elapsed !== null && <span className="muted">Dauer {elapsed}</span>}
        <span className="runlog__spacer" />
        <label className="check runlog__follow">
          <input type="checkbox" checked={follow} onChange={(e) => setFollow(e.target.checked)} />
          <span>Automatisch mitlaufen</span>
        </label>
        {canCancel && !done && <Confirm label="Lauf abbrechen" question="Lauf wirklich abbrechen? Der Prozess erhält SIGTERM, nach 10 s SIGKILL." confirmLabel="Ja, abbrechen" busy={cancelBusy || requested} onConfirm={() => void cancel()} />}
      </div>

      {requested && !done && <Alert tone="warn">Abbruch angefordert … Der Lauf endet, sobald der Prozess beendet ist.</Alert>}
      {cancelError !== null && <Alert tone="err">{cancelError}</Alert>}
      {problem !== null && <Alert tone="err">{problem}</Alert>}
      {trimmed && <p className="hint">Sehr lange Ausgabe: Der Anfang wird hier nicht mehr angezeigt.</p>}

      <pre ref={preRef} className="runout runlog__out" tabIndex={0} aria-label="Ausgabe" onScroll={onScroll} data-testid="runlog-out">
        {pieces.length === 0 ? (
          <span className="runlog__sys">{done ? '(keine Ausgabe)' : 'Noch keine Ausgabe …'}</span>
        ) : (
          pieces.map((piece) => (
            <Fragment key={piece.key}>
              {piece.tag !== null && (
                <span className={'runlog__tag runlog__tag--' + piece.stream} aria-hidden="true">
                  {piece.tag}
                </span>
              )}
              <span className={'runlog__' + piece.stream}>{piece.text}</span>
            </Fragment>
          ))
        )}
      </pre>
    </div>
  );
}
