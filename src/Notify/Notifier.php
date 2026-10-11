<?php

declare(strict_types=1);

namespace Meridian\Notify;

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
    ) {
    }

    /**
     * @return list<string>
     */
    public function tick(): array
    {
        $lines = [];
        $queued = $this->evaluate();
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
        $jobs = $this->db->fetchAll('SELECT id FROM jobs WHERE notify_on_failure = 1 OR notify_on_recovery = 1 ORDER BY id');
        foreach ($jobs as $row) {
            $jobId = Row::int($row, 'id');
            $queued += $this->db->immediate(fn (): int => $this->evaluateJob($jobId));
        }

        return $queued;
    }

    private function evaluateJob(int $jobId): int
    {
        $job = $this->db->fetchOne(
            'SELECT id, name, retry_count, notify_on_failure, notify_failure_after, notify_on_recovery, notify_channels FROM jobs WHERE id = :id',
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
        }
        $this->db->execute(
            'INSERT INTO job_notify_state (job_id, last_run_id, consecutive_failures, notified) VALUES (:job, :last, :failures, :notified)
             ON CONFLICT (job_id) DO UPDATE SET last_run_id = :last, consecutive_failures = :failures, notified = :notified',
            ['job' => $jobId, 'last' => $last, 'failures' => $failures, 'notified' => $notified ? 1 : 0],
        );

        return $queued;
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $run
     */
    private function enqueue(array $job, string $kind, array $run, int $failures): int
    {
        $jobId = Row::int($job, 'id');
        $mode = Row::string($job, 'notify_channels');
        if ($mode === NotifySettings::NONE) {
            return 0;
        }
        $ids = $mode === NotifySettings::SELECTED
            ? $this->db->fetchAll('SELECT c.id FROM notification_channels c JOIN job_channels j ON j.channel_id = c.id WHERE j.job_id = :job AND c.is_enabled = 1 ORDER BY c.id', ['job' => $jobId])
            : $this->db->fetchAll('SELECT id FROM notification_channels WHERE is_enabled = 1 ORDER BY id');
        $now = Timestamp::format($this->clock->now());
        $detail = Row::stringOrNull($run, 'note');
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
                    'run' => Row::int($run, 'id'),
                    'status' => Row::string($run, 'status'),
                    'at' => Row::stringOrNull($run, 'finished_at') ?? $now,
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
            "SELECT id, kind, channel_id, job_name, run_status, occurred_at, detail, failures FROM notification_outbox
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
        );
        try {
            $secret = $this->channels->secret($channel->id);
            $sender->send($channel, $secret, $message);
            $secret?->wipe();

            return null;
        } catch (SendFailed $e) {
            return $e->getMessage();
        } catch (\Throwable) {
            // Meldung und Stacktrace können Hosts oder Zugangsdaten enthalten: nichts davon weitergeben.
            return SendFailed::SECRET;
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
