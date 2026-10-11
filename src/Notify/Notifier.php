<?php

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Job\NotifySettings;
use Meridian\Job\Row;
use Meridian\Security\SecretMasker;

/**
 * Der Zusteller (ADR 0007 N5): läuft im `worker-http`-Prozess (dort gibt es Netz), nie in den Workern, die Läufe
 * abschließen, und nie in der Web-Anfrage.
 *
 *  1. {@see self::evaluate()}: beendete Läufe je Job auswerten (Fehlerserie, Wiederherstellung) und Einträge in den Ausgang legen.
 *  2. {@see self::deliver()}: fällige Einträge übernehmen (atomar, mehrere Prozesse stellen nie doppelt zu) und senden;
 *     bei Fehlern nach 1, 5 und 30 Minuten erneut, danach `failed`.
 *  3. {@see self::prune()}: Erledigtes nach 30 Tagen löschen.
 *  Dazu (ADR 0010): Jobs mit `auto_disable_after` werden nach der Fehlerserie deaktiviert (Audit ohne Benutzer, optional Nachricht),
 *  und {@see self::evaluateTls()} warnt vor ablaufenden Zertifikaten.
 *
 * Ein Fehlschlag zählt erst, wenn kein Wiederholungsversuch mehr folgt (`attempt` > `retry_count`). Läufe, die älter als
 * {@see self::FRESH_SECONDS} sind, zählen für die Serie, lösen aber keine Nachricht mehr aus (kein Nachholen alter Fehler).
 * Liefert nur feste Texte mit Nummern und Anzahlen — nie Hosts, Adressen, Benutzer oder Antworten.
 */
final class Notifier
{
    public const FRESH_SECONDS = 1800;
    public const MAX_ATTEMPTS = 3;
    public const BATCH = 10;
    public const KEEP_DAYS = 30;
    private const RUNS_PER_JOB = 200;
    private const CLAIM_SECONDS = 300;
    private const DETAIL_BYTES = 300;

    /** Wartezeit nach dem n-ten gescheiterten Versuch (Sekunden). */
    private const BACKOFF = [1 => 60, 2 => 300];

    /**
     * @param array<string, Sender> $senders nach Kanalart
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly ChannelStore $channels,
        private readonly array $senders,
        private readonly SecretMasker $masker,
        /** @var (\Closure(): ?string)|null öffentliche Adresse von Meridian für Links (Einstellung app.public_url) */
        private readonly ?\Closure $baseUrl = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function tick(): array
    {
        $lines = [];
        $queued = $this->evaluate() + $this->evaluateTls() + $this->evaluateMonitors() + $this->evaluateRuntime();
        if ($queued > 0) {
            $lines[] = 'Benachrichtigungen eingereiht: ' . $queued . '.';
        }
        $result = $this->deliver();
        if ($result['sent'] > 0 || $result['failed'] > 0 || $result['retry'] > 0) {
            $lines[] = 'Benachrichtigungen: ' . $result['sent'] . ' zugestellt, ' . $result['retry'] . ' zur Wiederholung, ' . $result['failed'] . ' endgültig fehlgeschlagen.';
        }
        $this->prune();

        return $lines;
    }

    /**
     * @return int Anzahl neu eingereihter Nachrichten
     */
    public function evaluate(): int
    {
        $queued = 0;
        $jobs = $this->db->fetchAll('SELECT id FROM jobs WHERE notify_on_failure = 1 OR notify_on_recovery = 1 OR auto_disable_after IS NOT NULL ORDER BY id');
        foreach ($jobs as $row) {
            $jobId = Row::int($row, 'id');
            $queued += $this->db->immediate(fn (): int => $this->evaluateJob($jobId));
        }

        return $queued;
    }

    private function evaluateJob(int $jobId): int
    {
        $job = $this->db->fetchOne(
            'SELECT id, name, retry_count, is_enabled, notify_on_failure, notify_failure_after, notify_on_recovery, notify_channels, auto_disable_after, notify_on_disable FROM jobs WHERE id = :id',
            ['id' => $jobId],
        );
        if ($job === null) {
            return 0;
        }
        $state = $this->db->fetchOne('SELECT last_run_id, consecutive_failures, notified FROM job_notify_state WHERE job_id = :id', ['id' => $jobId]);
        $last = $state === null ? 0 : Row::int($state, 'last_run_id');
        $failures = $state === null ? 0 : Row::int($state, 'consecutive_failures');
        $notified = $state !== null && Row::bool($state, 'notified');

        $runs = $this->db->fetchAll(
            "SELECT id, status, attempt, finished_at, note FROM runs
              WHERE job_id = :job AND id > :last AND status IN ('ok', 'failed', 'timeout') AND trigger <> 'test'
              ORDER BY id LIMIT :limit",
            ['job' => $jobId, 'last' => $last, 'limit' => self::RUNS_PER_JOB],
        );
        if ($runs === []) {
            return 0;
        }

        $now = $this->clock->now();
        $retries = Row::int($job, 'retry_count');
        $after = Row::int($job, 'notify_failure_after');
        $disableAfter = Row::intOrNull($job, 'auto_disable_after');
        $enabled = Row::bool($job, 'is_enabled');
        $queued = 0;
        foreach ($runs as $run) {
            $last = Row::int($run, 'id');
            $finishedAt = Row::stringOrNull($run, 'finished_at');
            $fresh = false;
            if ($finishedAt !== null) {
                try {
                    $fresh = $now->getTimestamp() - Timestamp::parse($finishedAt)->getTimestamp() <= self::FRESH_SECONDS;
                } catch (\InvalidArgumentException) {
                    $fresh = false;
                }
            }
            $status = Row::string($run, 'status');
            if ($status === 'ok') {
                if ($notified && Row::bool($job, 'notify_on_recovery') && $fresh) {
                    $queued += $this->enqueue($job, 'recovery', $run, $failures);
                }
                $failures = 0;
                $notified = false;
                continue;
            }
            if (Row::int($run, 'attempt') <= $retries) {
                continue; // es folgt noch ein Wiederholungsversuch
            }
            ++$failures;
            if (Row::bool($job, 'notify_on_failure') && !$notified && $failures >= $after && $fresh) {
                $queued += $this->enqueue($job, 'failure', $run, $failures);
                $notified = true;
            }
            if ($enabled && $disableAfter !== null && $failures >= $disableAfter && $fresh) {
                $queued += $this->autoDisable($job, $run, $failures);
                $enabled = false;
                $failures = 0;
                $notified = false;
            }
        }
        $this->db->execute(
            'INSERT INTO job_notify_state (job_id, last_run_id, consecutive_failures, notified) VALUES (:job, :last, :failures, :notified)
             ON CONFLICT (job_id) DO UPDATE SET last_run_id = :last, consecutive_failures = :failures, notified = :notified',
            ['job' => $jobId, 'last' => $last, 'failures' => $failures, 'notified' => $notified ? 1 : 0],
        );

        return $queued;
    }

    /**
     * Deaktiviert den Job nach einer Fehlerserie (nur wenn der Besitzer es für diesen Job eingestellt hat) und meldet es.
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $run
     */
    private function autoDisable(array $job, array $run, int $failures): int
    {
        $jobId = Row::int($job, 'id');
        $now = Timestamp::format($this->clock->now());
        $this->db->execute('UPDATE jobs SET is_enabled = 0, next_run_at = NULL, updated_at = :now WHERE id = :id AND is_enabled = 1', ['now' => $now, 'id' => $jobId]);
        (new AuditLog($this->db, $this->clock, $this->masker))->record(null, 'job.auto_disabled', 'job:' . $jobId . ' nach ' . $failures . ' Fehlschlägen in Folge');
        if (!Row::bool($job, 'notify_on_disable')) {
            return 0;
        }

        return $this->enqueue($job, 'disabled', $run, $failures);
    }

    /**
     * Herzschlag-Überwachungen (ADR 0011): ohne Lebenszeichen bis `Frist = (letzter Ping oder Beginn) + Intervall + Toleranz`
     * wechselt der Zustand auf „down“ (einmal gemeldet); ein Ping danach setzt „up“ und merkt die Wiederherstellung vor,
     * die hier einmal gemeldet wird. Die Zustandsänderung und der Ausgang stehen in einer Transaktion.
     *
     * @return int Anzahl neu eingereihter Nachrichten
     */
    public function evaluateMonitors(): int
    {
        $now = $this->clock->now();
        $nowText = Timestamp::format($now);
        $queued = 0;
        $rows = $this->db->fetchAll(
            'SELECT id, name, kind, state, armed_at, last_ping_at, interval_seconds, grace_seconds, notify_on_down, notify_on_up, notify_channels, recovery_pending, down_pending, last_check_note
               FROM monitors WHERE is_enabled = 1 ORDER BY id',
        );
        foreach ($rows as $row) {
            $id = Row::int($row, 'id');
            $state = Row::string($row, 'state');
            $job = ['id' => null, 'name' => Row::string($row, 'name'), 'notify_channels' => Row::string($row, 'notify_channels')];
            if (Row::bool($row, 'recovery_pending')) {
                $queued += $this->db->immediate(function () use ($id, $row, $job, $nowText): int {
                    if ($this->db->execute('UPDATE monitors SET recovery_pending = 0 WHERE id = :id AND recovery_pending = 1', ['id' => $id]) !== 1) {
                        return 0;
                    }

                    return Row::bool($row, 'notify_on_up') ? $this->enqueueRaw($job, 'monitor_up', null, null, $nowText, Row::string($row, 'kind') === 'heartbeat' ? 'Das Lebenszeichen ist wieder da.' : 'Die Prüfung ist wieder erfolgreich.', 0) : 0;
                });
                continue;
            }
            if (Row::string($row, 'kind') !== 'heartbeat') {
                // Aktive Prüfung (ADR 0018): den Ausfall hat die Prüfung schon festgestellt (nach n Fehlversuchen); hier nur melden.
                if (Row::bool($row, 'down_pending')) {
                    $queued += $this->db->immediate(function () use ($id, $row, $job, $nowText): int {
                        if ($this->db->execute('UPDATE monitors SET down_pending = 0 WHERE id = :id AND down_pending = 1', ['id' => $id]) !== 1) {
                            return 0;
                        }

                        return Row::bool($row, 'notify_on_down') ? $this->enqueueRaw($job, 'monitor_down', null, null, $nowText, Row::stringOrNull($row, 'last_check_note') ?? 'Die Prüfung ist fehlgeschlagen.', 0) : 0;
                    });
                }
                continue;
            }
            if ($state === 'down') {
                continue;
            }
            $base = Row::stringOrNull($row, 'last_ping_at') ?? Row::string($row, 'armed_at');
            try {
                $deadline = Timestamp::parse($base)->getTimestamp() + Row::int($row, 'interval_seconds') + Row::int($row, 'grace_seconds');
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($now->getTimestamp() <= $deadline) {
                continue;
            }
            $queued += $this->db->immediate(function () use ($id, $row, $job, $nowText, $state): int {
                if ($this->db->execute("UPDATE monitors SET state = 'down', state_since = :now WHERE id = :id AND state = :state AND is_enabled = 1", ['now' => $nowText, 'id' => $id, 'state' => $state]) !== 1) {
                    return 0;
                }
                $minutes = intdiv(Row::int($row, 'interval_seconds'), 60);
                $detail = Row::stringOrNull($row, 'last_ping_at') === null
                    ? 'Es kam noch kein Lebenszeichen (erwartet etwa alle ' . $minutes . ' Min).'
                    : 'Das letzte Lebenszeichen war um ' . Row::string($row, 'last_ping_at') . ' (UTC); erwartet etwa alle ' . $minutes . ' Min.';

                return Row::bool($row, 'notify_on_down') ? $this->enqueueRaw($job, 'monitor_down', null, null, $nowText, $detail, 0) : 0;
            });
        }

        return $queued;
    }

    /** Warns once per completed successful run, using a robust baseline without test runs. */
    public function evaluateRuntime(): int
    {
        $queued = 0;
        $jobs = $this->db->fetchAll('SELECT id FROM jobs WHERE runtime_warn_percent IS NOT NULL ORDER BY id');
        foreach ($jobs as $row) {
            $jobId = Row::int($row, 'id');
            $queued += $this->db->immediate(function () use ($jobId): int {
                $job = $this->db->fetchOne('SELECT id, name, notify_channels, runtime_warn_percent FROM jobs WHERE id = :id AND runtime_warn_percent IS NOT NULL', ['id' => $jobId]);
                if ($job === null) {
                    return 0;
                }
                $runs = $this->db->fetchAll("SELECT id, status, finished_at, duration_ms FROM runs WHERE job_id = :job AND runtime_checked = 0 AND status = 'ok' AND trigger <> 'test' ORDER BY id LIMIT :limit", ['job' => $jobId, 'limit' => self::RUNS_PER_JOB]);
                $count = 0;
                foreach ($runs as $run) {
                    $last = Row::int($run, 'id');
                    $this->db->execute('UPDATE runs SET runtime_checked = 1 WHERE id = :id', ['id' => $last]);
                    $finished = Row::stringOrNull($run, 'finished_at');
                    $duration = Row::intOrNull($run, 'duration_ms');
                    if ($finished === null || $duration === null || $duration <= 0) {
                        continue;
                    }
                    try {
                        $age = $this->clock->now()->getTimestamp() - Timestamp::parse($finished)->getTimestamp();
                    } catch (\InvalidArgumentException) {
                        continue;
                    }
                    if ($age < 0 || $age > self::FRESH_SECONDS) {
                        continue;
                    }
                    $history = $this->db->fetchAll("SELECT duration_ms FROM runs WHERE job_id = :job AND id <> :run AND status = 'ok' AND trigger <> 'test' AND duration_ms > 0 AND finished_at <= :finished ORDER BY finished_at DESC, id DESC LIMIT 20", ['job' => $jobId, 'run' => $last, 'finished' => $finished]);
                    $values = array_map(static fn (array $sample): int => Row::int($sample, 'duration_ms'), $history);
                    if (count($values) < 5) {
                        continue;
                    }
                    sort($values, SORT_NUMERIC);
                    $middle = intdiv(count($values), 2);
                    $median = count($values) % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
                    if ($duration / $median * 100 < Row::int($job, 'runtime_warn_percent')) {
                        continue;
                    }
                    $detail = 'Laufzeit: ' . $duration . ' ms; üblich: ' . (int) round($median) . ' ms (Median aus ' . count($values) . ' erfolgreichen Läufen).';
                    $count += $this->enqueueRaw($job, 'slow', $last, 'ok', $finished, $detail, 0);
                }
                return $count;
            });
        }
        return $queued;
    }

    /**
     * Warnt einmal je Ablaufdatum, wenn das beim Lauf gesehene Zertifikat in höchstens `tls_warn_days` Tagen abläuft.
     *
     * @return int Anzahl neu eingereihter Nachrichten
     */
    public function evaluateTls(): int
    {
        $now = $this->clock->now();
        $rows = $this->db->fetchAll(
            'SELECT j.id, j.name, j.notify_channels, j.tls_warn_days, t.expires_at, t.notified_for FROM jobs j JOIN job_tls t ON t.job_id = j.id
              WHERE j.tls_warn_days IS NOT NULL AND j.is_enabled = 1 ORDER BY j.id',
        );
        $queued = 0;
        foreach ($rows as $row) {
            $expires = Row::string($row, 'expires_at');
            if (Row::stringOrNull($row, 'notified_for') === $expires) {
                continue;
            }
            try {
                $expiresAt = Timestamp::parse($expires);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $days = (int) floor(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
            if ($days > Row::int($row, 'tls_warn_days')) {
                continue;
            }
            $jobId = Row::int($row, 'id');
            $queued += $this->db->immediate(function () use ($row, $jobId, $expires, $days, $now): int {
                $claimed = $this->db->execute('UPDATE job_tls SET notified_for = :exp WHERE job_id = :id AND (notified_for IS NULL OR notified_for <> :exp)', ['exp' => $expires, 'id' => $jobId]);
                if ($claimed !== 1) {
                    return 0;
                }
                $detail = $days < 0 ? 'Das Zertifikat ist am ' . $expires . ' (UTC) abgelaufen.' : 'Das Zertifikat läuft am ' . $expires . ' (UTC) ab, in ' . $days . ' Tag(en).';

                return $this->enqueueRaw($row, 'tls_expiry', null, null, Timestamp::format($now), $detail, 0);
            });
        }

        return $queued;
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $run
     */
    private function enqueue(array $job, string $kind, array $run, int $failures): int
    {
        return $this->enqueueRaw($job, $kind, Row::int($run, 'id'), Row::string($run, 'status'), Row::stringOrNull($run, 'finished_at'), Row::stringOrNull($run, 'note'), $failures);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function enqueueRaw(array $job, string $kind, ?int $runId, ?string $runStatus, ?string $occurredAt, ?string $note, int $failures): int
    {
        $jobId = Row::intOrNull($job, 'id');
        $mode = Row::string($job, 'notify_channels');
        if ($mode === NotifySettings::NONE) {
            return 0;
        }
        $ids = $mode === NotifySettings::SELECTED && $jobId !== null
            ? $this->db->fetchAll('SELECT c.id FROM notification_channels c JOIN job_channels j ON j.channel_id = c.id WHERE j.job_id = :job AND c.is_enabled = 1 ORDER BY c.id', ['job' => $jobId])
            : $this->db->fetchAll('SELECT id FROM notification_channels WHERE is_enabled = 1 ORDER BY id');
        $now = Timestamp::format($this->clock->now());
        $detail = $note;
        if ($detail !== null) {
            $detail = $this->masker->mask(mb_strcut($detail, 0, self::DETAIL_BYTES));
        }
        $count = 0;
        foreach ($ids as $channel) {
            $this->db->execute(
                'INSERT INTO notification_outbox (kind, channel_id, job_id, job_name, run_id, run_status, occurred_at, detail, failures, status, next_attempt_at, created_at)
                 VALUES (:kind, :channel, :job, :name, :run, :status, :at, :detail, :failures, \'pending\', :now, :now)',
                [
                    'kind' => $kind,
                    'channel' => Row::int($channel, 'id'),
                    'job' => $jobId,
                    'name' => Row::string($job, 'name'),
                    'run' => $runId,
                    'status' => $runStatus,
                    'at' => $occurredAt ?? $now,
                    'detail' => $detail,
                    'failures' => $failures,
                    'now' => $now,
                ],
            );
            ++$count;
        }

        return $count;
    }

    /**
     * @return array{sent: int, retry: int, failed: int}
     */
    public function deliver(): array
    {
        $result = ['sent' => 0, 'retry' => 0, 'failed' => 0];
        $nowText = Timestamp::format($this->clock->now());
        $due = $this->db->fetchAll(
            "SELECT id, kind, channel_id, job_id, job_name, run_status, occurred_at, detail, failures FROM notification_outbox
              WHERE status = 'pending' AND next_attempt_at <= :now ORDER BY id LIMIT :limit",
            ['now' => $nowText, 'limit' => self::BATCH],
        );
        foreach ($due as $row) {
            $id = Row::int($row, 'id');
            if (!$this->claim($id)) {
                continue;
            }
            $attempts = Row::int($this->db->fetchOne('SELECT attempts FROM notification_outbox WHERE id = :id', ['id' => $id]) ?? ['attempts' => 1], 'attempts');
            $error = $this->send($row);
            if ($error === null) {
                $this->db->execute("UPDATE notification_outbox SET status = 'sent', sent_at = :now, last_error = NULL WHERE id = :id", ['now' => Timestamp::format($this->clock->now()), 'id' => $id]);
                ++$result['sent'];
            } elseif ($attempts >= self::MAX_ATTEMPTS || $error === SendFailed::DISABLED) {
                $this->db->execute("UPDATE notification_outbox SET status = 'failed', last_error = :error WHERE id = :id", ['error' => $error, 'id' => $id]);
                ++$result['failed'];
            } else {
                $this->db->execute(
                    'UPDATE notification_outbox SET next_attempt_at = :next, last_error = :error WHERE id = :id',
                    ['next' => Timestamp::format($this->clock->now()->modify('+' . (self::BACKOFF[$attempts] ?? 1800) . ' seconds')), 'error' => $error, 'id' => $id],
                );
                ++$result['retry'];
            }
        }

        return $result;
    }

    /**
     * Übernimmt einen fälligen Eintrag: nur wer die Zeile ändert, sendet. Bleibt der Prozess hängen, wird sie nach der
     * Frist erneut fällig. false = ein anderer Prozess war schneller oder der Eintrag ist nicht (mehr) fällig.
     */
    public function claim(int $id): bool
    {
        $now = $this->clock->now();

        return $this->db->execute(
            "UPDATE notification_outbox SET attempts = attempts + 1, next_attempt_at = :lease WHERE id = :id AND status = 'pending' AND next_attempt_at <= :now",
            ['lease' => Timestamp::format($now->modify('+' . self::CLAIM_SECONDS . ' seconds')), 'id' => $id, 'now' => Timestamp::format($now)],
        ) === 1;
    }

    /**
     * Link zum Job, wenn eine öffentliche Adresse eingestellt ist (nie bei Testnachrichten und gelöschten Jobs).
     *
     * @param array<string, mixed> $row
     */
    private function link(array $row): ?string
    {
        $jobId = Row::intOrNull($row, 'job_id');
        if ($this->baseUrl === null || $jobId === null || Row::string($row, 'kind') === 'test') {
            return null;
        }
        $base = ($this->baseUrl)();
        if ($base === null || $base === '') {
            return null;
        }
        $exists = $this->db->fetchOne('SELECT id FROM jobs WHERE id = :id', ['id' => $jobId]);

        return $exists === null ? null : $base . '/app/#/jobs/' . $jobId;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return string|null null = zugestellt, sonst die feste Fehlermeldung
     */
    private function send(array $row): ?string
    {
        $channel = $this->channels->find(Row::int($row, 'channel_id'));
        if ($channel === null || !$channel->isEnabled) {
            return SendFailed::DISABLED;
        }
        $sender = $this->senders[$channel->type->value] ?? null;
        if ($sender === null) {
            return SendFailed::REJECTED;
        }
        $message = new Message(
            Row::string($row, 'kind'),
            $this->masker->mask(Row::string($row, 'job_name')),
            Row::stringOrNull($row, 'run_status'),
            Row::string($row, 'occurred_at'),
            Row::stringOrNull($row, 'detail'),
            Row::int($row, 'failures'),
            $this->link($row),
            $this->channels->languageFor($channel),
        );
        try {
            $secret = $this->channels->secret($channel->id);
        } catch (\Throwable) {
            return SendFailed::SECRET;
        }
        try {
            $sender->send($channel, $secret, $message);

            return null;
        } catch (SendFailed $e) {
            return $e->getMessage();
        } catch (\Throwable) {
            // Meldung und Stacktrace können Hosts oder Zugangsdaten enthalten: nichts davon weitergeben.
            return SendFailed::UNEXPECTED;
        } finally {
            $secret?->wipe();
        }
    }

    public function prune(): void
    {
        $this->db->execute(
            "DELETE FROM notification_outbox WHERE status <> 'pending' AND created_at < :cutoff",
            ['cutoff' => Timestamp::format($this->clock->now()->modify('-' . self::KEEP_DAYS . ' days'))],
        );
    }
}
