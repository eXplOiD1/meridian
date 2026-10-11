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
                    m.last_ping_at, m.notify_on_down, m.notify_on_up, m.notify_channels, m.created_at
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
                    m.last_ping_at, m.notify_on_down, m.notify_on_up, m.notify_channels, m.created_at
             FROM monitors m LEFT JOIN categories c ON c.id = m.category_id
             WHERE m.id = :id AND (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))',
            $scope->sqlParameters() + ['id' => $id],
        );

        return $row === null ? null : self::record($row);
    }

    public function nameTaken(string $name, ?int $exceptId): bool
    {
        $row = $this->db->fetchOne('SELECT id FROM monitors WHERE name = :name COLLATE NOCASE AND (:except IS NULL OR id <> :except)', ['name' => $name, 'except' => $exceptId]);

        return $row !== null;
    }

    public function insert(MonitorDraft $draft, int $userId, string $tokenHash, string $now): int
    {
        $this->db->execute(
            "INSERT INTO monitors (name, category_id, token_hash, interval_seconds, grace_seconds, is_enabled, state, state_since, armed_at, notify_on_down, notify_on_up, notify_channels, created_by, created_at, updated_at)
             VALUES (:name, :category, :hash, :interval, :grace, :enabled, 'waiting', :now, :now, :down, :up, :channels, :user, :now, :now)",
            [
                'name' => $draft->name,
                'category' => $draft->categoryId,
                'hash' => $tokenHash,
                'interval' => $draft->intervalSeconds,
                'grace' => $draft->graceSeconds,
                'enabled' => $draft->isEnabled ? 1 : 0,
                'down' => $draft->notifyOnDown ? 1 : 0,
                'up' => $draft->notifyOnUp ? 1 : 0,
                'channels' => $draft->notifyChannels,
                'user' => $userId,
                'now' => $now,
            ],
        );

        return $this->db->lastInsertId();
    }

    /**
     * @param bool $rearm Frist neu beginnen lassen (Intervall/Toleranz geändert oder wieder aktiviert): Zustand „wartet“
     */
    public function update(int $id, MonitorDraft $draft, bool $rearm, string $now): void
    {
        $this->db->execute(
            'UPDATE monitors SET name = :name, category_id = :category, interval_seconds = :interval, grace_seconds = :grace, is_enabled = :enabled,
                notify_on_down = :down, notify_on_up = :up, notify_channels = :channels, updated_at = :now WHERE id = :id',
            [
                'name' => $draft->name,
                'category' => $draft->categoryId,
                'interval' => $draft->intervalSeconds,
                'grace' => $draft->graceSeconds,
                'enabled' => $draft->isEnabled ? 1 : 0,
                'down' => $draft->notifyOnDown ? 1 : 0,
                'up' => $draft->notifyOnUp ? 1 : 0,
                'channels' => $draft->notifyChannels,
                'now' => $now,
                'id' => $id,
            ],
        );
        if ($rearm) {
            $this->db->execute("UPDATE monitors SET state = 'waiting', state_since = :now, armed_at = :now, last_ping_at = NULL, recovery_pending = 0 WHERE id = :id", ['now' => $now, 'id' => $id]);
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
        $row = $this->db->fetchOne('SELECT id, is_enabled, last_ping_at, state FROM monitors WHERE token_hash = :hash', ['hash' => $hash]);
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
            Row::string($row, 'notify_channels'),
            Row::string($row, 'created_at'),
        );
    }
}
