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

    public function record(?int $userId, string $action, ?string $target = null): void
    {
        $masked = $target === null ? null : mb_substr($this->masker->mask($target), 0, self::MAX_TARGET);
        $this->db->execute(
            'INSERT INTO audit_log (user_id, action, target, created_at) VALUES (:u, :a, :t, :c)',
            ['u' => $userId, 'a' => $action, 't' => $masked, 'c' => $this->clock->now()->format('c')],
        );
    }
}
