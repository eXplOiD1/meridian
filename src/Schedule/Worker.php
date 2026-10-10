<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Runner\Heartbeat;
use Meridian\Runner\JobType;
use Meridian\Runner\NullLiveLog;
use Meridian\Runner\RunnerRegistry;
use Meridian\Runner\RunRequest;
use Meridian\Runner\RunResult;
use Meridian\Runner\StopReason;
use Meridian\Security\SecretMasker;

/**
 * Worker (ADR 0004 E5): übernimmt fällige Läufe seiner Job-Typen atomar, führt sie über den Runner des Typs aus und
 * speichert das Ergebnis maskiert. Legt Wiederholungen mit wachsendem Abstand an. Kennt seine Kennung
 * ({@see WorkerIdentity}) und Typen, **nie** die Scheduler-Sperre: hängende Läufe beendet der Planer ({@see StaleRuns}).
 *
 *  - Übernehmen ohne Sperre per `UPDATE … WHERE status = 'queued'` mit rowCount() === 1 in `immediate()`; die
 *    Überlappungsprüfung steht in derselben Transaktion (SQLite serialisiert sie: zwei Worker starten nie beide einen
 *    zweiten Lauf desselben Jobs).
 *  - Läufe deaktivierter Jobs werden nicht ausgeführt („skipped“ mit Notiz) — außer Testläufen (E4).
 *  - Manuelle Läufe, Testläufe und Wiederholungen mit auslösendem Benutzer prüfen beim Übernehmen erneut, ob
 *    dieser Benutzer den Job noch starten darf (H1, {@see RunAuthorizer}); sonst „skipped“.
 *  - Abbruch durch Benutzer oder Beenden des Workers → „aborted“ mit fester Notiz, nie wiederholt.
 *  - Jeder Lauf bekommt einen eigenen SecretMasker (H4); Ausgabe und Notiz: erst damit maskieren, dann kürzen,
 *    dann speichern. Der Masker wird danach verworfen.
 */
final class Worker
{
    /** Höchstens so viele Läufe je Takt; der Rest folgt im nächsten Takt. */
    public const MAX_RUNS_PER_TICK = 20;

    /** Gespeicherte Ausgabe je Lauf, nach dem Maskieren. */
    public const MAX_OUTPUT_BYTES = 65536;

    public const MAX_NOTE_BYTES = 500;

    public const NOTE_NO_RUNNER = 'Für diesen Job-Typ ist noch kein Runner eingerichtet.';
    public const NOTE_RUNNER_ERROR = 'Der Runner ist mit einem unerwarteten Fehler abgebrochen.';
    public const NOTE_JOB_DISABLED = 'Übersprungen: Der Job ist deaktiviert.';
    public const NOTE_STARTER_FORBIDDEN = 'Übersprungen: Der auslösende Benutzer darf diesen Job nicht mehr starten.';
    public const NOTE_JOB_INVALID = 'Abgebrochen: Der Job ist gelöscht oder hat einen unbekannten Typ.';
    public const NOTE_STALE = 'Abgebrochen: Kein Lebenszeichen des ausführenden Prozesses mehr (z. B. Neustart oder Absturz). Der Lauf wird nicht automatisch wiederholt.';
    public const NOTE_FINISH_FAILED = 'Fehlgeschlagen: Das Ergebnis konnte nicht gespeichert werden.';
    public const NOTE_CANCELLED = 'Abgebrochen durch Benutzer.';
    public const NOTE_WORKER_STOPPED = 'Abgebrochen: Der Worker wurde beendet (Neustart oder Update). Der Lauf wird nicht automatisch wiederholt.';

    /** Versuche, das Ergebnis zu speichern (z. B. bei kurz gesperrter Datenbank), bevor der Ersatzeintrag greift. */
    public const FINISH_ATTEMPTS = 3;
    public const NOTE_RETRY = 'Wiederholung nach Fehler.';

    private const TRUNCATED = "\n[gekürzt]";

    /** @var non-empty-list<JobType> */
    private readonly array $types;

    /** JSON-Liste der Typen für `json_each()` (nur Enum-Werte). */
    private readonly string $typesJson;

    /**
     * @param non-empty-list<JobType>                $types       Job-Typen, die dieser Worker übernimmt
     * @param (\Closure(Heartbeat): Heartbeat)|null $wrapHeartbeat nur `--inline-worker`: {@see LeaseHeartbeat}
     * @param LiveLogFactory|null                    $liveLogs    Live-Log je Lauf (Betrieb: {@see DbLiveLogFactory}); null = keins
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly WorkerIdentity $me,
        array $types,
        private readonly RunnerRegistry $runners,
        private readonly RunAuthorizer $authorizer,
        private readonly ?\Closure $wrapHeartbeat = null,
        private readonly ?LiveLogFactory $liveLogs = null,
    ) {
        $this->types = array_values(array_unique($types, SORT_REGULAR));
        $this->typesJson = json_encode(array_map(static fn (JobType $t): string => $t->value, $this->types), JSON_THROW_ON_ERROR);
    }

    public function identity(): WorkerIdentity
    {
        return $this->me;
    }

    /**
     * Arbeitet fällige Läufe der eigenen Typen ab, höchstens `$maxRuns` ausgeführte Läufe je Aufruf (ein Worker-Kind
     * ruft mit 1 auf; übersprungene zählen nicht).
     *
     * @param callable(): bool $stopRequested true → keinen weiteren Lauf anfangen und den laufenden abbrechen (SIGTERM)
     *
     * @return list<RunEvent>
     */
    public function work(callable $stopRequested, int $maxRuns = self::MAX_RUNS_PER_TICK): array
    {
        $candidates = $this->db->fetchAll(
            "SELECT r.id FROM runs r JOIN jobs j ON j.id = r.job_id WHERE r.status = 'queued' AND (r.scheduled_for IS NULL OR r.scheduled_for <= :now) AND j.type IN (SELECT value FROM json_each(:types)) ORDER BY r.scheduled_for IS NOT NULL, r.scheduled_for, r.id LIMIT 20",
            ['now' => Timestamp::format($this->clock->now()), 'types' => $this->typesJson],
        );

        $events = [];
        $executed = 0;
        foreach ($candidates as $row) {
            if ($executed >= $maxRuns || $stopRequested()) {
                break;
            }
            $runId = $row['id'] ?? null;
            if (!is_int($runId)) {
                continue;
            }
            $claim = $this->claim($runId);
            if ($claim instanceof RunEvent) {
                $events[] = $claim;
                continue;
            }
            if ($claim === null) {
                continue;
            }
            $events[] = $this->execute($claim, $stopRequested);
            ++$executed;
        }

        return $events;
    }

    /**
     * Trägt diesen Worker in `workers` ein bzw. frischt `seen_at` auf (alle 10 s). Fähigkeiten ohne Geheimnisse.
     *
     * @param array<string, bool|int|string|list<string>> $caps
     */
    public function announce(array $caps = []): void
    {
        $now = Timestamp::format($this->clock->now());
        $host = gethostname();
        $pid = getmypid();
        $this->db->execute(
            'INSERT INTO workers (id, kind, host, pid, caps_json, started_at, seen_at) VALUES (:id, :kind, :host, :pid, :caps, :now, :now)
             ON CONFLICT (id) DO UPDATE SET seen_at = excluded.seen_at',
            [
                'id' => $this->me->id,
                'kind' => $this->me->kind->value,
                'host' => $host === false ? 'host' : substr($host, 0, 64),
                'pid' => $pid === false ? 0 : $pid,
                'caps' => json_encode($caps === [] ? new \stdClass() : $caps, JSON_THROW_ON_ERROR),
                'now' => $now,
            ],
        );
    }

    /** Beim Beenden: eigene Zeile in `workers` löschen. */
    public function retire(): void
    {
        $this->db->execute('DELETE FROM workers WHERE id = :id', ['id' => $this->me->id]);
    }

    /**
     * Übernimmt einen wartenden Lauf eines eigenen Typs. Gibt den Auftrag zurück, ein Ereignis (Lauf wurde
     * stattdessen übersprungen/abgebrochen) oder null (nicht übernommen: schon vergeben, wartet noch, fremder Typ).
     */
    public function claim(int $runId): RunRequest|RunEvent|null
    {
        return $this->db->immediate(function () use ($runId): RunRequest|RunEvent|null {
            $now = Timestamp::format($this->clock->now());
            $row = $this->db->fetchOne(
                "SELECT r.id, r.job_id, r.trigger, r.attempt, r.started_by, j.id AS job_exists, j.type, j.is_enabled, j.overlap_policy FROM runs r LEFT JOIN jobs j ON j.id = r.job_id WHERE r.id = :id AND r.status = 'queued'",
                ['id' => $runId],
            );
            if ($row === null || !is_int($row['job_id']) || !is_int($row['attempt'])) {
                return null;
            }
            $jobId = $row['job_id'];
            $type = is_string($row['type']) ? JobType::tryFrom($row['type']) : null;
            $trigger = is_string($row['trigger']) ? RunTrigger::tryFrom($row['trigger']) : null;
            $startedBy = is_int($row['started_by']) ? $row['started_by'] : null;

            if ($row['job_exists'] === null || $type === null || $trigger === null) {
                return $this->closeQueued($runId, $jobId, RunStatus::Aborted, self::NOTE_JOB_INVALID, $now);
            }
            if (!in_array($type, $this->types, true)) {
                // Fremder Typ: übernimmt ein anderer Worker (z. B. Shell nur im Shell-Worker ohne Netz).
                return null;
            }
            // Testläufe auch für deaktivierte Jobs („Speichern und testen“, E4); alles andere nur für aktive.
            if ($row['is_enabled'] !== 1 && $trigger !== RunTrigger::Test) {
                return $this->closeQueued($runId, $jobId, RunStatus::Skipped, self::NOTE_JOB_DISABLED, $now);
            }
            if (!$this->starterMayStart($trigger, $startedBy, $jobId)) {
                return $this->closeQueued($runId, $jobId, RunStatus::Skipped, self::NOTE_STARTER_FORBIDDEN, $now);
            }
            if ($row['overlap_policy'] !== OverlapPolicy::Parallel->value) {
                $busy = $this->db->fetchOne(
                    "SELECT 1 AS busy FROM runs WHERE job_id = :job AND status = 'running' AND id <> :id LIMIT 1",
                    ['job' => $jobId, 'id' => $runId],
                );
                if ($busy !== null) {
                    // skip/queue: erst starten, wenn der laufende fertig ist.
                    return null;
                }
            }

            $claimed = $this->db->execute(
                "UPDATE runs SET status = 'running', started_at = :now, heartbeat_at = :now, worker = :me WHERE id = :id AND status = 'queued'",
                ['now' => $now, 'me' => $this->me->id, 'id' => $runId],
            );
            if ($claimed !== 1) {
                return null;
            }

            return new RunRequest($runId, $jobId, $type, $trigger, $row['attempt'], $startedBy);
        });
    }

    /**
     * H1: Ein von einem Benutzer ausgelöster Lauf startet nur, wenn dieser Benutzer ihn jetzt noch starten darf.
     * Manuell/Test ohne Benutzer (gelöscht: `ON DELETE SET NULL`) startet nie. Geplante Läufe und Wiederholungen
     * geplanter Läufe haben keinen auslösenden Benutzer.
     */
    private function starterMayStart(RunTrigger $trigger, ?int $startedBy, int $jobId): bool
    {
        if ($startedBy === null) {
            return $trigger !== RunTrigger::Manual && $trigger !== RunTrigger::Test;
        }

        // Jeder Lauf mit auslösendem Benutzer (manuell, Test und deren Wiederholungen) wird erneut geprüft.
        try {
            return $this->authorizer->mayStart($startedBy, $jobId);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Führt einen übernommenen Lauf aus und speichert das Ergebnis. Der Runner hält den Lauf über den
     * Herzschlag am Leben; der Herzschlag meldet Abbruch durch Benutzer, Beenden des Workers oder „hängend“.
     *
     * @param (callable(): bool)|null $stopRequested true → Worker wird beendet, laufenden Lauf abbrechen
     */
    public function execute(RunRequest $request, ?callable $stopRequested = null): RunEvent
    {
        $started = $this->clock->now();
        $heartbeat = new RunHeartbeat($this->db, $this->clock, $this->me->id, $request->runId, $stopRequested === null ? null : \Closure::fromCallable($stopRequested));
        if ($this->wrapHeartbeat !== null) {
            $heartbeat = ($this->wrapHeartbeat)($heartbeat);
        }
        // H4: ein Masker nur für diesen Lauf. Geheimnisse eines Laufs bleiben nie für den nächsten registriert.
        $masker = new SecretMasker();
        $runner = $this->runners->for($request->type);
        if ($runner === null) {
            // Ein fehlender Runner ist ein Einrichtungsfehler: Wiederholen hilft nicht.
            $result = RunResult::failed('', self::NOTE_NO_RUNNER, retryable: false);
        } else {
            $live = $this->liveLogs?->forRun($request->runId, $masker) ?? new NullLiveLog();
            try {
                $result = $runner->run($request, $heartbeat, $masker, $live);
            } catch (\Throwable) {
                // Die Meldung der Ausnahme kann Payload enthalten: nicht speichern.
                $result = RunResult::failed('', self::NOTE_RUNNER_ERROR);
            } finally {
                $live->flush();
            }
        }

        // Abbruch durch Benutzer oder Beenden des Workers: immer „aborted“ mit fester Notiz, nie wiederholt — egal,
        // was der Runner daraus gemacht hat. „Stale“: der Lauf gehört nicht mehr diesem Worker, das Speichern unten
        // trifft keine Zeile mehr.
        $aborted = match ($heartbeat->stopReason()) {
            StopReason::Cancelled => RunResult::aborted($result->output, self::NOTE_CANCELLED),
            StopReason::WorkerStopping => RunResult::aborted($result->output, self::NOTE_WORKER_STOPPED),
            StopReason::Stale, null => null,
        };
        if ($aborted !== null) {
            $result = $result->outputBytes === null ? $aborted : $aborted->withOutputBytes($result->outputBytes);
        }

        return $this->finish($request, $result, $started, $masker);
    }

    /**
     * Speichert das Ergebnis, bei Fehlern (z. B. SQLITE_BUSY) mit begrenzten Wiederholungen. Gelingt es nicht,
     * wird der Lauf mit fester Notiz als „failed“ beendet; scheitert auch das, bleibt er „running“, sein
     * Herzschlag veraltet und der Planer ({@see StaleRuns}) beendet ihn nach Ablauf der Sperrdauer.
     */
    private function finish(RunRequest $request, RunResult $result, \DateTimeImmutable $started, SecretMasker $masker): RunEvent
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->storeResult($request, $result, $started, $masker);
            } catch (\Throwable) {
                if ($attempt >= self::FINISH_ATTEMPTS) {
                    break;
                }
                usleep(50_000 * $attempt);
            }
        }

        $changed = $this->db->execute(
            "UPDATE runs SET status = 'failed', finished_at = :now, note = :note WHERE id = :id AND status = 'running' AND worker = :me",
            ['now' => Timestamp::format($this->clock->now()), 'note' => self::NOTE_FINISH_FAILED, 'id' => $request->runId, 'me' => $this->me->id],
        );

        return new RunEvent($request->jobId, $request->runId, $changed === 1 ? RunStatus::Failed : RunStatus::Aborted);
    }

    private function storeResult(RunRequest $request, RunResult $result, \DateTimeImmutable $started, SecretMasker $masker): RunEvent
    {
        $output = self::maskAndTruncate($masker, $result->output, self::MAX_OUTPUT_BYTES);
        $note = $result->note === null ? null : self::maskAndTruncate($masker, $result->note, self::MAX_NOTE_BYTES);

        return $this->db->immediate(function () use ($request, $result, $started, $output, $note): RunEvent {
            $now = $this->clock->now();
            $durationMs = max(0, (int) round(((float) $now->format('U.u') - (float) $started->format('U.u')) * 1000.0));
            $updated = $this->db->execute(
                "UPDATE runs SET status = :status, finished_at = :now, duration_ms = :duration, exit_code = :exit, http_status = :http, output = :output, output_bytes = :bytes, note = CASE WHEN :note IS NULL THEN note WHEN note IS NULL THEN :note ELSE note || ' ' || :note END WHERE id = :id AND status = 'running' AND worker = :me",
                [
                    'status' => $result->status->value,
                    'now' => Timestamp::format($now),
                    'duration' => $durationMs,
                    'exit' => $result->exitCode,
                    'http' => $result->httpStatus,
                    'output' => $output,
                    'bytes' => $result->outputBytes,
                    'note' => $note,
                    'id' => $request->runId,
                    'me' => $this->me->id,
                ],
            );
            // Die Notiz des Planers (z. B. „Nachgeholt …“) bleibt erhalten, die des Ergebnisses wird angehängt.
            if ($updated !== 1) {
                // Inzwischen als hängend abgebrochen (oder gelöscht): nichts überschreiben, nichts wiederholen.
                return new RunEvent($request->jobId, $request->runId, RunStatus::Aborted);
            }

            if ($result->retryable && $result->status->isRetryable() && $request->trigger !== RunTrigger::Test) {
                $this->scheduleRetry($request, $now);
            }

            return new RunEvent($request->jobId, $request->runId, $result->status);
        });
    }

    private function scheduleRetry(RunRequest $request, \DateTimeImmutable $now): void
    {
        $job = $this->db->fetchOne(
            'SELECT retry_count, retry_delay_seconds, is_enabled FROM jobs WHERE id = :id',
            ['id' => $request->jobId],
        );
        if ($job === null || $job['is_enabled'] !== 1 || !is_int($job['retry_count']) || !is_int($job['retry_delay_seconds'])) {
            return;
        }
        if (!RetryPolicy::allowsAnother($job['retry_count'], $request->attempt)) {
            return;
        }

        $delay = RetryPolicy::delaySeconds($job['retry_delay_seconds'], $request->attempt);
        $this->db->execute(
            // Die Wiederholung übernimmt den auslösenden Benutzer: H1 prüft ihn beim Übernehmen erneut.
            'INSERT INTO runs (job_id, trigger, status, scheduled_for, attempt, note, started_by) VALUES (:job, :trigger, :status, :scheduled, :attempt, :note, :started_by)',
            [
                'job' => $request->jobId,
                'trigger' => RunTrigger::Retry->value,
                'status' => RunStatus::Queued->value,
                'scheduled' => Timestamp::format($now->modify('+' . $delay . ' seconds')),
                'attempt' => $request->attempt + 1,
                'note' => self::NOTE_RETRY,
                'started_by' => $request->startedBy,
            ],
        );
    }

    private function closeQueued(int $runId, int $jobId, RunStatus $status, string $note, string $now): ?RunEvent
    {
        $changed = $this->db->execute(
            "UPDATE runs SET status = :status, finished_at = :now, note = :note WHERE id = :id AND status = 'queued'",
            ['status' => $status->value, 'now' => $now, 'note' => $note, 'id' => $runId],
        );

        return $changed === 1 ? new RunEvent($jobId, $runId, $status) : null;
    }

    /**
     * Erst maskieren, dann kürzen: sonst könnte der Schnitt ein Geheimnis so teilen, dass der Masker es
     * nicht mehr erkennt. Der Schnitt trennt keine UTF-8-Zeichen.
     */
    private static function maskAndTruncate(SecretMasker $masker, string $text, int $maxBytes): string
    {
        $masked = $masker->mask($text);
        if (strlen($masked) <= $maxBytes) {
            return $masked;
        }

        $cut = substr($masked, 0, $maxBytes - strlen(self::TRUNCATED));
        if (preg_match('//u', $masked) === 1) {
            // Angeschnittenes Mehrbyte-Zeichen am Ende entfernen.
            $cut = (string) preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $cut);
        }

        return $cut . self::TRUNCATED;
    }
}
