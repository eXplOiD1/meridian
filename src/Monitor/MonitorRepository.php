<?php

declare(strict_types=1);

namespace Meridian\Monitor;

use Meridian\Database\Connection;
use Meridian\Job\Row;
use Meridian\Security\CategoryScope;

/**
 * Zugriff auf `monitors`. Listen und Detail benutzen dasselbe Sichtbarkeits-Prädikat wie die Jobs
 * (`WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))`): ohne Kategorie nur im Bereich „alle“.
 * Der Token-Hash wird hier nur geschrieben und für den Ping geprüft, nie in einem Datensatz zurückgegeben.
 */
final class MonitorRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<MonitorRecord>
     */
    public function list(CategoryScope $scope): array
    {
        $rows = $this->db->fetchAll(
            'SELECT m.id, m.name, m.category_id, c.name AS category_name, m.interval_seconds, m.grace_seconds, m.is_enabled, m.state, m.state_since,
                    m.last_ping_at, m.notify_on_down, m.notify_on_up, m.notify_channels, m.notify_channel_ids, (SELECT json_group_array(mc.channel_id) FROM monitor_channels mc WHERE mc.monitor_id = m.id) AS channel_ids, m.created_at,
                    m.kind, m.display_target, m.expected_status, m.fail_after, m.consecutive_failures, m.last_check_at, m.last_check_ok, m.last_latency_ms, m.last_check_note
             FROM monitors m LEFT JOIN categories c ON c.id = m.category_id
             WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))
             ORDER BY m.name COLLATE NOCASE, m.id',
            $scope->sqlParameters(),
        );

        return array_map(self::record(...), $rows);
    }

    public function findVisible(int $id, CategoryScope $scope): ?MonitorRecord
    {
        $row = $this->db->fetchOne(
            'SELECT m.id, m.name, m.category_id, c.name AS category_name, m.interval_seconds, m.grace_seconds, m.is_enabled, m.state, m.state_since,
                    m.last_ping_at, m.notify_on_down, m.notify_on_up, m.notify_channels, m.notify_channel_ids, (SELECT json_group_array(mc.channel_id) FROM monitor_channels mc WHERE mc.monitor_id = m.id) AS channel_ids, m.created_at,
                    m.kind, m.display_target, m.expected_status, m.fail_after, m.consecutive_failures, m.last_check_at, m.last_check_ok, m.last_latency_ms, m.last_check_note
             FROM monitors m LEFT JOIN categories c ON c.id = m.category_id
             WHERE m.id = :id AND (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))',
            $scope->sqlParameters() + ['id' => $id],
        );

        return $row === null ? null : self::record($row);
    }

    private function writeChannels(int $id, MonitorDraft $draft): void
    {
        $this->db->execute('DELETE FROM monitor_channels WHERE monitor_id = :id', ['id' => $id]);
        if ($draft->notifyChannels === 'selected') {
            foreach ($draft->channelIds as $channelId) {
                $this->db->execute('INSERT INTO monitor_channels (monitor_id, channel_id) VALUES (:monitor, :channel)', ['monitor' => $id, 'channel' => $channelId]);
            }
        }
    }

    private function validateChannels(MonitorDraft $draft): void
    {
        if ($draft->notifyChannels !== 'selected') {
            return;
        }
        foreach ($draft->channelIds as $id) {
            if ($this->db->fetchOne('SELECT id FROM notification_channels WHERE id = :id', ['id' => $id]) === null) {
                throw \Meridian\Http\ValidationFailed::field('channel_ids', 'Mindestens ein gewählter Kanal existiert nicht (mehr).');
            }
        }
    }

    public function nameTaken(string $name, ?int $exceptId): bool
    {
        $row = $this->db->fetchOne('SELECT id FROM monitors WHERE name = :name COLLATE NOCASE AND (:except IS NULL OR id <> :except)', ['name' => $name, 'except' => $exceptId]);

        return $row !== null;
    }

    public function insert(MonitorDraft $draft, int $userId, string $tokenHash, string $now, ?string $targetEnc = null): int
    {
        $this->validateChannels($draft);
        $this->db->execute(
            "INSERT INTO monitors (name, category_id, token_hash, interval_seconds, grace_seconds, is_enabled, state, state_since, armed_at, notify_on_down, notify_on_up, notify_channels, created_by, created_at, updated_at,
                kind, target_enc, display_target, expected_status, fail_after, notify_channel_ids)
             VALUES (:name, :category, :hash, :interval, :grace, :enabled, 'waiting', :now, :now, :down, :up, :channels, :user, :now, :now,
                :kind, :target, :display, :expected, :fail_after, :channel_ids)",
            [
                'name' => $draft->name,
                'category' => $draft->categoryId,
                'hash' => $tokenHash,
                'interval' => $draft->intervalSeconds,
                'grace' => $draft->graceSeconds,
                'enabled' => $draft->isEnabled ? 1 : 0,
                'down' => $draft->notifyOnDown ? 1 : 0,
                'up' => $draft->notifyOnUp ? 1 : 0,
                'channels' => $draft->notifyChannels === 'none' ? 'none' : 'all',
                'channel_ids' => $draft->notifyChannels === 'selected' ? json_encode($draft->channelIds, JSON_THROW_ON_ERROR) : null,
                'user' => $userId,
                'now' => $now,
                'kind' => $draft->kind->value,
                'target' => $targetEnc,
                'display' => $draft->displayTarget,
                'expected' => $draft->expectedStatus,
                'fail_after' => $draft->failAfter,
            ],
        );

        $id = $this->db->lastInsertId();
        $this->writeChannels($id, $draft);
        return $id;
    }

    /**
     * @param bool $rearm Frist neu beginnen lassen (Intervall/Toleranz geändert oder wieder aktiviert): Zustand „wartet“
     */
    public function update(int $id, MonitorDraft $draft, bool $rearm, string $now, ?string $targetEnc = null): void
    {
        $this->validateChannels($draft);
        $this->db->execute(
            'UPDATE monitors SET name = :name, category_id = :category, interval_seconds = :interval, grace_seconds = :grace, is_enabled = :enabled,
                notify_on_down = :down, notify_on_up = :up, notify_channels = :channels, notify_channel_ids = :channel_ids, expected_status = :expected, fail_after = :fail_after, updated_at = :now WHERE id = :id',
            [
                'name' => $draft->name,
                'category' => $draft->categoryId,
                'interval' => $draft->intervalSeconds,
                'grace' => $draft->graceSeconds,
                'enabled' => $draft->isEnabled ? 1 : 0,
                'down' => $draft->notifyOnDown ? 1 : 0,
                'up' => $draft->notifyOnUp ? 1 : 0,
                'channels' => $draft->notifyChannels === 'none' ? 'none' : 'all',
                'channel_ids' => $draft->notifyChannels === 'selected' ? json_encode($draft->channelIds, JSON_THROW_ON_ERROR) : null,
                'expected' => $draft->expectedStatus,
                'fail_after' => $draft->failAfter,
                'now' => $now,
                'id' => $id,
            ],
        );
        $this->writeChannels($id, $draft);
        if ($targetEnc !== null) {
            $this->db->execute('UPDATE monitors SET target_enc = :target, display_target = :display WHERE id = :id', ['target' => $targetEnc, 'display' => $draft->displayTarget, 'id' => $id]);
        }
        if ($rearm) {
            $this->db->execute(
                "UPDATE monitors SET state = 'waiting', state_since = :now, armed_at = :now, last_ping_at = NULL, recovery_pending = 0, down_pending = 0,
                    consecutive_failures = 0, last_check_at = NULL, last_check_ok = NULL, last_latency_ms = NULL, last_check_note = NULL WHERE id = :id",
                ['now' => $now, 'id' => $id],
            );
        }
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM monitors WHERE id = :id', ['id' => $id]);
    }

    public function replaceToken(int $id, string $tokenHash, string $now): void
    {
        $this->db->execute('UPDATE monitors SET token_hash = :hash, updated_at = :now WHERE id = :id', ['hash' => $tokenHash, 'now' => $now, 'id' => $id]);
    }

    /**
     * Für den Ping: Überwachung zum Token-Hash.
     *
     * @return array{id: int, enabled: bool, last_ping_at: string|null, state: string}|null
     */
    public function byTokenHash(string $hash): ?array
    {
        $row = $this->db->fetchOne('SELECT id, is_enabled, last_ping_at, state FROM monitors WHERE token_hash = :hash AND kind = \'heartbeat\'', ['hash' => $hash]);
        if ($row === null) {
            return null;
        }

        return ['id' => Row::int($row, 'id'), 'enabled' => Row::bool($row, 'is_enabled'), 'last_ping_at' => Row::stringOrNull($row, 'last_ping_at'), 'state' => Row::string($row, 'state')];
    }

    /**
     * Lebenszeichen: Zustand „up“; kam es nach einem „down“, merkt `recovery_pending` die Wiederherstellungsmeldung vor.
     */
    public function recordPing(int $id, string $now): void
    {
        $this->db->execute(
            "UPDATE monitors SET last_ping_at = :now,
                recovery_pending = CASE WHEN state = 'down' THEN 1 ELSE recovery_pending END,
                state_since = CASE WHEN state <> 'up' THEN :now ELSE state_since END,
                state = 'up'
             WHERE id = :id AND is_enabled = 1",
            ['now' => $now, 'id' => $id],
        );
    }

    /**
     * Das verschlüsselte Ziel (nur für den Bearbeiten-Endpunkt und die Prüfung); null bei Herzschlag.
     */
    public function targetEnc(int $id): ?string
    {
        $row = $this->db->fetchOne('SELECT target_enc FROM monitors WHERE id = :id', ['id' => $id]);

        return $row === null ? null : Row::stringOrNull($row, 'target_enc');
    }

    /**
     * Aktive Prüfungen, die laufen: aktiv, mit Ziel. Wer fällig ist, entscheidet der Aufrufer (letzte Prüfung + Intervall).
     *
     * @return list<array{id: int, kind: MonitorKind, category_id: int|null, target_enc: string, expected_status: string, interval_seconds: int, last_check_at: string|null}>
     */
    public function activeForChecks(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, kind, category_id, target_enc, expected_status, interval_seconds, last_check_at FROM monitors
              WHERE is_enabled = 1 AND kind <> 'heartbeat' AND target_enc IS NOT NULL ORDER BY id",
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => Row::int($row, 'id'),
                'kind' => MonitorKind::from(Row::string($row, 'kind')),
                'category_id' => Row::intOrNull($row, 'category_id'),
                'target_enc' => Row::string($row, 'target_enc'),
                'expected_status' => Row::string($row, 'expected_status'),
                'interval_seconds' => Row::int($row, 'interval_seconds'),
                'last_check_at' => Row::stringOrNull($row, 'last_check_at'),
            ];
        }

        return $result;
    }

    /**
     * Beansprucht eine Prüfung: nur wer den bisherigen Zeitpunkt noch sieht, prüft (mehrere Worker prüfen nie doppelt).
     */
    public function claimCheck(int $id, ?string $seenLastCheckAt, string $now): bool
    {
        return $this->db->execute('UPDATE monitors SET last_check_at = :now WHERE id = :id AND is_enabled = 1 AND last_check_at IS :seen', ['now' => $now, 'id' => $id, 'seen' => $seenLastCheckAt]) === 1;
    }

    /**
     * Ergebnis einer Prüfung. Ausfall erst nach `fail_after` Fehlschlägen in Folge (`down_pending` merkt die Meldung vor),
     * Rückkehr nach einem Ausfall merkt `recovery_pending` vor. Die Notiz ist ein fester Text, nie eine Antwort des Ziels.
     *
     * @return string neuer Zustand
     */
    public function recordCheck(int $id, bool $ok, ?int $latencyMs, string $note, string $now): string
    {
        return $this->db->immediate(function () use ($id, $ok, $latencyMs, $note, $now): string {
            $row = $this->db->fetchOne('SELECT state, fail_after, consecutive_failures FROM monitors WHERE id = :id AND is_enabled = 1 AND kind <> \'heartbeat\'', ['id' => $id]);
            if ($row === null) {
                return 'waiting';
            }
            $state = Row::string($row, 'state');
            if ($ok) {
                $this->db->execute(
                    "UPDATE monitors SET consecutive_failures = 0, last_check_ok = 1, last_latency_ms = :latency, last_check_note = NULL, last_check_at = :now,
                        recovery_pending = CASE WHEN state = 'down' THEN 1 ELSE recovery_pending END,
                        down_pending = 0,
                        state_since = CASE WHEN state <> 'up' THEN :now ELSE state_since END,
                        state = 'up'
                     WHERE id = :id",
                    ['latency' => $latencyMs, 'now' => $now, 'id' => $id],
                );

                return 'up';
            }
            $failures = Row::int($row, 'consecutive_failures') + 1;
            $goesDown = $failures >= Row::int($row, 'fail_after') && $state !== 'down';
            $this->db->execute(
                "UPDATE monitors SET consecutive_failures = :failures, last_check_ok = 0, last_latency_ms = NULL, last_check_note = :note, last_check_at = :now,
                    state = CASE WHEN :down = 1 THEN 'down' ELSE state END,
                    state_since = CASE WHEN :down = 1 THEN :now ELSE state_since END,
                    down_pending = CASE WHEN :down = 1 THEN 1 ELSE down_pending END,
                    recovery_pending = CASE WHEN :down = 1 THEN 0 ELSE recovery_pending END
                 WHERE id = :id",
                ['failures' => $failures, 'note' => $note, 'now' => $now, 'down' => $goesDown ? 1 : 0, 'id' => $id],
            );

            return $goesDown ? 'down' : $state;
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function record(array $row): MonitorRecord
    {
        return new MonitorRecord(
            Row::int($row, 'id'),
            Row::string($row, 'name'),
            Row::intOrNull($row, 'category_id'),
            Row::stringOrNull($row, 'category_name'),
            Row::int($row, 'interval_seconds'),
            Row::int($row, 'grace_seconds'),
            Row::bool($row, 'is_enabled'),
            Row::string($row, 'state'),
            Row::string($row, 'state_since'),
            Row::stringOrNull($row, 'last_ping_at'),
            Row::bool($row, 'notify_on_down'),
            Row::bool($row, 'notify_on_up'),
            Row::string($row, 'notify_channels') === 'none' ? 'none' : (Row::stringOrNull($row, 'notify_channel_ids') === null ? 'all' : 'selected'),
            Row::string($row, 'created_at'),
            MonitorKind::from(Row::string($row, 'kind')),
            Row::stringOrNull($row, 'display_target'),
            Row::string($row, 'expected_status'),
            Row::int($row, 'fail_after'),
            Row::int($row, 'consecutive_failures'),
            Row::stringOrNull($row, 'last_check_at'),
            ($row['last_check_ok'] ?? null) === null ? null : Row::bool($row, 'last_check_ok'),
            Row::intOrNull($row, 'last_latency_ms'),
            Row::stringOrNull($row, 'last_check_note'),
            \Meridian\Notify\ChannelIds::decode(Row::string($row, 'channel_ids')),
        );
    }
}
