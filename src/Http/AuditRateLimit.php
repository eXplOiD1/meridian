<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Job\Row;

/**
 * Stundenlimit je Benutzer, gezählt über Einträge im Audit-Log (ein Eintrag je erlaubtem Abruf).
 *
 * Ein Aufrufer prüft und schreibt den Audit-Eintrag in **einer** sofort schreibenden Transaktion
 * (`Connection::immediate()`), damit zwei gleichzeitige Anfragen nicht beide durch die Grenze kommen.
 */
final class AuditRateLimit
{
    private const WINDOW_SECONDS = 3600;

    /**
     * Sekunden bis zum nächsten erlaubten Abruf, oder null, solange das Limit nicht erreicht ist.
     *
     * @param string      $action     Audit-Aktion, die gezählt wird
     * @param string|null $alsoAction zweite Aktion, die im selben Zähler mitläuft (null = nur `$action`)
     */
    public static function retryAfter(
        Connection $db,
        Clock $clock,
        int $userId,
        int $maxPerHour,
        string $action,
        ?string $alsoAction = null,
    ): ?int {
        $now = $clock->now();
        $row = $db->fetchOne(
            'SELECT COUNT(*) AS n, MIN(CAST(strftime(\'%s\', created_at) AS INTEGER)) AS oldest FROM audit_log
              WHERE user_id = :user AND action IN (:first, :second) AND julianday(created_at) > julianday(:since)',
            [
                'user' => $userId,
                'first' => $action,
                'second' => $alsoAction ?? $action,
                'since' => Timestamp::format($now->modify('-1 hour')),
            ],
        );
        if ($row === null || Row::int($row, 'n') < $maxPerHour) {
            return null;
        }
        // Ältester Abruf im Fenster (Unix-Sekunden); frei wird es, sobald er eine Stunde alt ist.
        $oldest = Row::intOrNull($row, 'oldest');
        $seconds = $oldest === null ? self::WINDOW_SECONDS : $oldest + self::WINDOW_SECONDS - $now->getTimestamp();

        return max(1, min(self::WINDOW_SECONDS, $seconds));
    }
}
