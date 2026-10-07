<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;

/**
 * Planer-Takt: findet fällige Jobs, legt pro Job höchstens einen Lauf an und schreibt next_run_at fort,
 * beides in EINER sofort schreibenden Transaktion (BEGIN IMMEDIATE), die zuerst die Sperre prüft.
 *
 * Regeln (normativ in .claude/skills/mer-scheduler):
 *  - next_run_at ist der nächste Termin nach dem zuletzt fälligen, berechnet aus dem Zeitplan.
 *  - Ein Termin, der mehr als {@see self::MISSED_AFTER_SECONDS} überfällig ist, gilt als verpasst.
 *    Verpasst und kein pünktlicher Termin: catch_up = 1 → genau ein Nachholen, sonst ein „skipped“-Vermerk.
 *    Danach liegt next_run_at in der Zukunft — mehr als ein Nachholen pro Job ist unmöglich.
 *  - Überlappung skip/parallel/queue entscheidet, ob der neue Lauf wartet oder als „skipped“ vermerkt wird.
 *  - Ungültiger Zeitplan: Vermerk ohne Payload, next_run_at = NULL, der Takt läuft weiter.
 *  - Der Planer schreibt nur Laufzustand (runs, jobs.next_run_at), nie Job-Einstellungen.
 */
final class Planner
{
    /** Ab dieser Verspätung gilt ein Termin als verpasst (Stillstand, nicht bloß ein langsamer Takt). */
    public const MISSED_AFTER_SECONDS = 300;

    /** Höchstens so viele fällige Jobs je Takt; der Rest folgt im nächsten Takt. */
    public const MAX_JOBS_PER_TICK = 200;

    /** Überlappung „parallel“: höchstens so viele wartende Läufe je Job. */
    public const MAX_QUEUED_PARALLEL = 5;

    public const NOTE_INVALID_SCHEDULE = 'Zeitplan ungültig: Cron-Ausdruck oder Zeitzone prüfen und den Job neu speichern.';
    public const NOTE_NO_FURTHER_RUN = 'Zeitplan hat keinen weiteren Termin: Cron-Ausdruck prüfen und den Job neu speichern.';
    public const NOTE_CAUGHT_UP = 'Nachgeholt: Termin während eines Stillstands des Schedulers verpasst (höchstens ein Nachholen).';
    public const NOTE_MISSED_SKIPPED = 'Übersprungen: Termin während eines Stillstands des Schedulers verpasst, Nachholen ist für diesen Job aus.';
    public const NOTE_EARLIER_MISSED = 'Frühere Termine wurden während eines Stillstands des Schedulers verpasst und nicht einzeln nachgeholt.';
    public const NOTE_OVERLAP_SKIP = 'Übersprungen: Der vorherige Lauf ist noch nicht fertig (Überlappung „überspringen“).';
    public const NOTE_OVERLAP_QUEUE_FULL = 'Übersprungen: Es wartet bereits ein Lauf (Überlappung „Warteschlange“).';
    public const NOTE_OVERLAP_PARALLEL_FULL = 'Übersprungen: Zu viele wartende Läufe (Überlappung „parallel“).';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SchedulerLease $lease,
    ) {
    }

    /**
     * Ein Planer-Durchlauf.
     *
     * @param callable(): bool $stopRequested true → keine weiteren Jobs anfangen (SIGTERM)
     *
     * @return list<RunEvent>
     */
    public function plan(callable $stopRequested): array
    {
        $now = $this->clock->now();
        $due = $this->db->fetchAll(
            'SELECT id FROM jobs WHERE is_enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= :now ORDER BY next_run_at, id LIMIT 200',
            ['now' => Timestamp::format($now)],
        );

        $events = [];
        foreach ($due as $row) {
            if ($stopRequested()) {
                break;
            }
            $jobId = $row['id'] ?? null;
            if (!is_int($jobId)) {
                continue;
            }
            $event = $this->db->immediate(fn (): RunEvent|false|null => $this->planJob($jobId));
            if ($event === false) {
                // Sperre verloren: sofort aufhören, der neue Besitzer plant weiter.
                break;
            }
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Setzt next_run_at eines Jobs auf den nächsten Termin ab jetzt (oder NULL, wenn deaktiviert oder der
     * Zeitplan ungültig ist). Für das Anlegen, Ändern und Aktivieren eines Jobs; der Takt selbst
     * initialisiert keine Jobs ohne next_run_at.
     */
    public function reschedule(int $jobId): ?\DateTimeImmutable
    {
        return $this->db->immediate(function () use ($jobId): ?\DateTimeImmutable {
            $job = $this->db->fetchOne('SELECT cron, timezone, is_enabled FROM jobs WHERE id = :id', ['id' => $jobId]);
            if ($job === null) {
                throw new \InvalidArgumentException('Job nicht gefunden.');
            }
            $next = null;
            if ($job['is_enabled'] === 1 && is_string($job['cron']) && is_string($job['timezone'])) {
                try {
                    $next = CronSchedule::forJob($job['cron'], $job['timezone'])->nextAfter($this->clock->now());
                } catch (\InvalidArgumentException | \RuntimeException) {
                    $next = null;
                }
            }
            $this->db->execute(
                'UPDATE jobs SET next_run_at = :next WHERE id = :id',
                ['next' => $next === null ? null : Timestamp::format($next), 'id' => $jobId],
            );

            return $next;
        });
    }

    /**
     * Läuft innerhalb von BEGIN IMMEDIATE.
     *
     * @return RunEvent|false|null false = Sperre nicht (mehr) gehalten, null = nichts zu tun
     */
    private function planJob(int $jobId): RunEvent|false|null
    {
        if (!$this->lease->isHeld()) {
            return false;
        }

        $now = $this->clock->now();
        $job = $this->db->fetchOne(
            'SELECT id, cron, timezone, overlap_policy, catch_up, is_enabled, next_run_at FROM jobs WHERE id = :id',
            ['id' => $jobId],
        );
        // Zwischen Auswahl und Transaktion deaktiviert, gelöscht oder schon geplant: nichts tun.
        if ($job === null || $job['is_enabled'] !== 1 || !is_string($job['next_run_at'])) {
            return null;
        }

        try {
            $scheduled = Timestamp::parse($job['next_run_at']);
        } catch (\InvalidArgumentException) {
            return $this->disableSchedule($jobId, null, $now, self::NOTE_INVALID_SCHEDULE);
        }
        if ($scheduled > $now) {
            return null;
        }

        try {
            $schedule = CronSchedule::forJob(
                is_string($job['cron']) ? $job['cron'] : '',
                is_string($job['timezone']) ? $job['timezone'] : '',
            );
        } catch (\InvalidArgumentException) {
            return $this->disableSchedule($jobId, $scheduled, $now, self::NOTE_INVALID_SCHEDULE);
        }

        try {
            [$latest, $next] = $this->dueSlots($schedule, $scheduled, $now);
        } catch (\RuntimeException) {
            return $this->disableSchedule($jobId, $scheduled, $now, self::NOTE_NO_FURTHER_RUN);
        }

        $missedBefore = $now->getTimestamp() - $scheduled->getTimestamp() > self::MISSED_AFTER_SECONDS;
        $this->db->execute(
            'UPDATE jobs SET next_run_at = :next WHERE id = :id',
            ['next' => Timestamp::format($next), 'id' => $jobId],
        );

        if ($latest !== null) {
            // Pünktlicher Termin: normaler Lauf. Verpasste frühere Termine gehen in ihm auf.
            return $this->createRun($jobId, $job['overlap_policy'], $latest, $now, $missedBefore ? self::NOTE_EARLIER_MISSED : null);
        }

        if ($job['catch_up'] === 1) {
            return $this->createRun($jobId, $job['overlap_policy'], $scheduled, $now, self::NOTE_CAUGHT_UP);
        }

        return $this->insertRun($jobId, RunStatus::Skipped, $scheduled, $now, self::NOTE_MISSED_SKIPPED);
    }

    /**
     * Letzter pünktlicher Termin (≤ jetzt, höchstens {@see self::MISSED_AFTER_SECONDS} alt) und der nächste
     * Termin danach. Kein pünktlicher Termin → [null, nächster Termin nach jetzt].
     *
     * @return array{0: ?\DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function dueSlots(CronSchedule $schedule, \DateTimeImmutable $scheduled, \DateTimeImmutable $now): array
    {
        $next = $schedule->nextAfter($scheduled);
        if ($next > $now) {
            // Nur der geplante Termin ist fällig; der nächste folgt aus dem Zeitplan, nicht aus „jetzt“.
            $onTime = $now->getTimestamp() - $scheduled->getTimestamp() <= self::MISSED_AFTER_SECONDS;

            return [$onTime ? $scheduled : null, $next];
        }

        // Mehrere Termine fällig: nur die Termine im Pünktlichkeitsfenster ansehen (wenige Minuten), nie
        // die ganze Stillstandszeit durchlaufen.
        $windowStart = $now->modify('-' . (self::MISSED_AFTER_SECONDS + 1) . ' seconds');
        $latest = null;
        $candidate = $schedule->nextAfter($windowStart > $scheduled ? $windowStart : $scheduled);
        while ($candidate <= $now) {
            $latest = $candidate;
            $candidate = $schedule->nextAfter($candidate);
        }
        if ($latest === null && $scheduled->getTimestamp() >= $windowStart->getTimestamp() + 1) {
            $latest = $scheduled;
        }

        return [$latest, $candidate];
    }

    private function createRun(int $jobId, mixed $policyValue, \DateTimeImmutable $scheduledFor, \DateTimeImmutable $now, ?string $note): RunEvent
    {
        $policy = is_string($policyValue) ? (OverlapPolicy::tryFrom($policyValue) ?? OverlapPolicy::Skip) : OverlapPolicy::Skip;
        $counts = $this->db->fetchOne(
            "SELECT COALESCE(SUM(status = 'running'), 0) AS running, COALESCE(SUM(status = 'queued'), 0) AS queued FROM runs WHERE job_id = :id AND status IN ('queued', 'running')",
            ['id' => $jobId],
        );
        $running = self::intOrZero($counts['running'] ?? null);
        $queued = self::intOrZero($counts['queued'] ?? null);

        $blockedBy = match ($policy) {
            OverlapPolicy::Skip => $running + $queued > 0 ? self::NOTE_OVERLAP_SKIP : null,
            OverlapPolicy::Queue => $queued > 0 ? self::NOTE_OVERLAP_QUEUE_FULL : null,
            OverlapPolicy::Parallel => $queued >= self::MAX_QUEUED_PARALLEL ? self::NOTE_OVERLAP_PARALLEL_FULL : null,
        };
        if ($blockedBy !== null) {
            return $this->insertRun($jobId, RunStatus::Skipped, $scheduledFor, $now, $blockedBy);
        }

        return $this->insertRun($jobId, RunStatus::Queued, $scheduledFor, $now, $note);
    }

    private static function intOrZero(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private function insertRun(int $jobId, RunStatus $status, ?\DateTimeImmutable $scheduledFor, \DateTimeImmutable $now, ?string $note): RunEvent
    {
        $this->db->execute(
            'INSERT INTO runs (job_id, trigger, status, scheduled_for, attempt, finished_at, note) VALUES (:job, :trigger, :status, :scheduled, 1, :finished, :note)',
            [
                'job' => $jobId,
                'trigger' => RunTrigger::Schedule->value,
                'status' => $status->value,
                'scheduled' => $scheduledFor === null ? null : Timestamp::format($scheduledFor),
                'finished' => $status === RunStatus::Skipped ? Timestamp::format($now) : null,
                'note' => $note,
            ],
        );

        return new RunEvent($jobId, $this->db->lastInsertId(), $status);
    }

    private function disableSchedule(int $jobId, ?\DateTimeImmutable $scheduled, \DateTimeImmutable $now, string $note): RunEvent
    {
        $this->db->execute('UPDATE jobs SET next_run_at = NULL WHERE id = :id', ['id' => $jobId]);

        return $this->insertRun($jobId, RunStatus::Skipped, $scheduled, $now, $note);
    }
}
