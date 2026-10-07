<?php

declare(strict_types=1);

namespace Meridian\Auth;

use Meridian\Database\Connection;
use Meridian\Security\SecretMasker;

/**
 * Nur anhängen. Das Ziel wird maskiert und danach gekürzt, nie umgekehrt.
 */
final class AuditLog
{
    private const MAX_TARGET = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SecretMasker $masker,
    ) {
    }

    /**
     * Neueste Einträge zuerst. Der Cursor before_id blättert rückwärts.
     *
     * @return list<array{id: int, user_id: int|null, username: string|null, action: string, target: string|null, created_at: string}>
     */
    public function recent(int $limit, ?int $beforeId = null, ?string $action = null): array
    {
        $rows = $this->db->fetchAll(
            'SELECT a.id, a.user_id, u.username, a.action, a.target, a.created_at
               FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
              WHERE (:before IS NULL OR a.id < :before) AND (:action IS NULL OR a.action = :action)
              ORDER BY a.id DESC
              LIMIT :limit',
            ['before' => $beforeId, 'action' => $action, 'limit' => $limit],
        );

        $entries = [];
        foreach ($rows as $row) {
            if (!isset($row['id'], $row['action'], $row['created_at']) || !is_int($row['id']) || !is_string($row['action']) || !is_string($row['created_at'])) {
                continue;
            }
            $entries[] = [
                'id' => $row['id'],
                'user_id' => isset($row['user_id']) && is_int($row['user_id']) ? $row['user_id'] : null,
                'username' => isset($row['username']) && is_string($row['username']) ? $row['username'] : null,
                'action' => $row['action'],
                'target' => isset($row['target']) && is_string($row['target']) ? $row['target'] : null,
                'created_at' => $row['created_at'],
            ];
        }

        return $entries;
    }

    public function record(?int $userId, string $action, ?string $target = null): void
    {
        $masked = $target === null ? null : mb_substr($this->masker->mask($target), 0, self::MAX_TARGET);
        $this->db->execute(
            'INSERT INTO audit_log (user_id, action, target, created_at) VALUES (:u, :a, :t, :c)',
            ['u' => $userId, 'a' => $action, 't' => $masked, 'c' => $this->clock->now()->format('c')],
        );
    }
}
