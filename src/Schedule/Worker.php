<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Runner\JobType;
use Meridian\Runner\RunnerRegistry;
use Meridian\Runner\RunRequest;
use Meridian\Runner\RunResult;
use Meridian\Security\SecretMasker;

/**
 * Worker: übernimmt fällige Läufe atomar, führt sie über den Runner des Job-Typs aus und speichert das
 * Ergebnis maskiert. Legt Wiederholungen mit wachsendem Abstand an.
 *
 *  - Übernehmen nur per `UPDATE … WHERE status = 'queued'` mit rowCount() === 1, unter der Sperre.
 *  - Läufe deaktivierter Jobs werden nicht ausgeführt („skipped“ mit Notiz).
 *  - Hängende Läufe eines früheren Prozesses werden „aborted“, nie neu gestartet.
 *  - Ausgabe und Notiz: erst maskieren, dann kürzen, dann speichern.
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
    public const NOTE_JOB_INVALID = 'Abgebrochen: Der Job ist gelöscht oder hat einen unbekannten Typ.';
    public const NOTE_STALE = 'Abgebrochen: Der ausführende Prozess lief nicht mehr (z. B. Neustart). Der Lauf wird nicht automatisch wiederholt.';
    public const NOTE_RETRY = 'Wiederholung nach Fehler.';

    private const TRUNCATED = "\n[gekürzt]";

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SchedulerLease $lease,
        private readonly RunnerRegistry $runners,
        private readonly SecretMasker $masker,
    ) {
    }

    /**
     * Arbeitet fällige Läufe ab.
     *
     * @param callable(): bool $stopRequested true → keinen weiteren Lauf anfangen (SIGTERM)
     *
     * @return list<RunEvent>
     */
    public function work(callable $stopRequested): array
    {
        $candidates = $this->db->fetchAll(
            "SELECT id FROM runs WHERE status = 'queued' AND (scheduled_for IS NULL OR scheduled_for <= :now) ORDER BY scheduled_for IS NOT NULL, scheduled_for, id LIMIT 20",
            ['now' => Timestamp::format($this->clock->now())],
        );

        $events = [];
        foreach ($candidates as $row) {
            // Vor jedem Lauf: Stopp? Sperre noch da (und verlängert)?
            if ($stopRequested() || !$this->lease->acquire()) {
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
            $events[] = $this->execute($claim);
        }

        return $events;
    }

    /**
     * Übernimmt einen wartenden Lauf. Gibt den Auftrag zurück, ein Ereignis (Lauf wurde stattdessen
     * übersprungen/abgebrochen) oder null (nicht übernommen: schon vergeben, wartet noch, keine Sperre).
     */
    public function claim(int $runId): RunRequest|RunEvent|null
    {
        return $this->db->immediate(function () use ($runId): RunRequest|RunEvent|null {
            if (!$this->lease->isHeld()) {
                return null;
            }
            $now = Timestamp::format($this->clock->now());
            $row = $this->db->fetchOne(
                "SELECT r.id, r.job_id, r.trigger, r.attempt, j.id AS job_exists, j.type, j.is_enabled, j.overlap_policy FROM runs r LEFT JOIN jobs j ON j.id = r.job_id WHERE r.id = :id AND r.status = 'queued'",
                ['id' => $runId],
            );
            if ($row === null || !is_int($row['job_id']) || !is_int($row['attempt'])) {
                return null;
            }
            $jobId = $row['job_id'];
            $type = is_string($row['type']) ? JobType::tryFrom($row['type']) : null;
            $trigger = is_string($row['trigger']) ? RunTrigger::tryFrom($row['trigger']) : null;

            if ($row['job_exists'] === null || $type === null || $trigger === null) {
                return $this->closeQueued($runId, $jobId, RunStatus::Aborted, self::NOTE_JOB_INVALID, $now);
            }
            if ($row['is_enabled'] !== 1) {
                return $this->closeQueued($runId, $jobId, RunStatus::Skipped, self::NOTE_JOB_DISABLED, $now);
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
                "UPDATE runs SET status = 'running', started_at = :now, worker = :me WHERE id = :id AND status = 'queued'",
                ['now' => $now, 'me' => $this->lease->owner(), 'id' => $runId],
            );
            if ($claimed !== 1) {
                return null;
            }

            return new RunRequest($runId, $jobId, $type, $trigger, $row['attempt']);
        });
    }

    /**
     * Führt einen übernommenen Lauf aus und speichert das Ergebnis.
     */
    public function execute(RunRequest $request): RunEvent
    {
        $started = $this->clock->now();
        $runner = $this->runners->for($request->type);
        $retryAllowed = true;
        if ($runner === null) {
            $result = RunResult::failed('', self::NOTE_NO_RUNNER);
            // Ein fehlender Runner ist ein Einrichtungsfehler: Wiederholen hilft nicht.
            $retryAllowed = false;
        } else {
            try {
                $result = $runner->run($request);
            } catch (\Throwable) {
                // Die Meldung der Ausnahme kann Payload enthalten: nicht speichern.
                $result = RunResult::failed('', self::NOTE_RUNNER_ERROR);
            }
        }

        return $this->finish($request, $result, $started, $retryAllowed);
    }

    /**
     * Markiert Läufe, die noch als „running“ gelten, aber keinem lebenden Prozess gehören, als abgebrochen.
     * Aufruf, sobald dieser Prozess die Sperre neu übernommen hat: Nur der Sperrinhaber führt aus, also
     * gehört jeder fremde „running“-Lauf einem beendeten oder abgelösten Prozess.
     *
     * @return list<RunEvent>
     */
    public function abortStale(): array
    {
        return $this->db->immediate(function (): array {
            if (!$this->lease->isHeld()) {
                return [];
            }
            $stale = $this->db->fetchAll(
                "SELECT id, job_id FROM runs WHERE status = 'running' AND (worker IS NULL OR worker <> :me) ORDER BY id",
                ['me' => $this->lease->owner()],
            );
            $events = [];
            foreach ($stale as $row) {
                if (!is_int($row['id']) || !is_int($row['job_id'])) {
                    continue;
                }
                $changed = $this->db->execute(
                    "UPDATE runs SET status = 'aborted', finished_at = :now, note = :note WHERE id = :id AND status = 'running'",
                    ['now' => Timestamp::format($this->clock->now()), 'note' => self::NOTE_STALE, 'id' => $row['id']],
                );
                if ($changed === 1) {
                    $events[] = new RunEvent($row['job_id'], $row['id'], RunStatus::Aborted);
                }
            }

            return $events;
        });
    }

    private function finish(RunRequest $request, RunResult $result, \DateTimeImmutable $started, bool $retryAllowed): RunEvent
    {
        $output = $this->maskAndTruncate($result->output, self::MAX_OUTPUT_BYTES);
        $note = $result->note === null ? null : $this->maskAndTruncate($result->note, self::MAX_NOTE_BYTES);

        return $this->db->immediate(function () use ($request, $result, $started, $retryAllowed, $output, $note): RunEvent {
            $now = $this->clock->now();
            $durationMs = max(0, (int) round(((float) $now->format('U.u') - (float) $started->format('U.u')) * 1000.0));
            $updated = $this->db->execute(
                "UPDATE runs SET status = :status, finished_at = :now, duration_ms = :duration, exit_code = :exit, http_status = :http, output = :output, note = CASE WHEN :note IS NULL THEN note WHEN note IS NULL THEN :note ELSE note || ' ' || :note END WHERE id = :id AND status = 'running' AND worker = :me",
                [
                    'status' => $result->status->value,
                    'now' => Timestamp::format($now),
                    'duration' => $durationMs,
                    'exit' => $result->exitCode,
                    'http' => $result->httpStatus,
                    'output' => $output,
                    'note' => $note,
                    'id' => $request->runId,
                    'me' => $this->lease->owner(),
                ],
            );
            // Die Notiz des Planers (z. B. „Nachgeholt …“) bleibt erhalten, die des Ergebnisses wird angehängt.
            if ($updated !== 1) {
                // Inzwischen als hängend abgebrochen (oder gelöscht): nichts überschreiben, nichts wiederholen.
                return new RunEvent($request->jobId, $request->runId, RunStatus::Aborted);
            }

            if ($retryAllowed && $result->status->isRetryable() && $request->trigger !== RunTrigger::Test) {
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
            'INSERT INTO runs (job_id, trigger, status, scheduled_for, attempt, note) VALUES (:job, :trigger, :status, :scheduled, :attempt, :note)',
            [
                'job' => $request->jobId,
                'trigger' => RunTrigger::Retry->value,
                'status' => RunStatus::Queued->value,
                'scheduled' => Timestamp::format($now->modify('+' . $delay . ' seconds')),
                'attempt' => $request->attempt + 1,
                'note' => self::NOTE_RETRY,
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
    private function maskAndTruncate(string $text, int $maxBytes): string
    {
        $masked = $this->masker->mask($text);
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
