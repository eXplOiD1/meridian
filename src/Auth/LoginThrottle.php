<?php

declare(strict_types=1);

namespace Meridian\Auth;

use Meridian\Database\Connection;

/**
 * Eskalierende Sperre nach Fehlversuchen, getrennt nach Benutzername und Client-IP.
 *
 * Unbekannte Benutzernamen werden genauso gezählt wie bekannte, damit die Sperre nichts verrät.
 * Gespeichert wird nur ein Hash des Namens bzw. der IP.
 */
final class LoginThrottle
{
    private const USER_THRESHOLD = 5;
    private const IP_THRESHOLD = 20;
    private const BASE_LOCK_SECONDS = 30;
    private const MAX_LOCK_SECONDS = 900;
    /** Fehlversuche verfallen, wenn eine Stunde lang keiner mehr dazukam. */
    private const WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Verbleibende Sperrzeit in Sekunden, 0 wenn nicht gesperrt.
     */
    public function lockedFor(string $username, string $ip): int
    {
        $now = $this->clock->now();
        $remaining = 0;
        foreach ([['user', self::subject($username)], ['ip', self::subject($ip)]] as [$scope, $subject]) {
            $row = $this->db->fetchOne(
                'SELECT locked_until FROM login_failures WHERE scope = :scope AND subject = :subject',
                ['scope' => $scope, 'subject' => $subject],
            );
            if ($row === null || !isset($row['locked_until']) || !is_string($row['locked_until'])) {
                continue;
            }
            $until = new \DateTimeImmutable($row['locked_until']);
            if ($until > $now) {
                $remaining = max($remaining, $until->getTimestamp() - $now->getTimestamp());
            }
        }

        return $remaining;
    }

    /**
     * @return bool true, wenn dieser Fehlversuch eine neue Sperre ausgelöst hat
     */
    public function recordFailure(string $username, string $ip): bool
    {
        $userLocked = $this->bump('user', self::subject($username), self::USER_THRESHOLD);
        $ipLocked = $this->bump('ip', self::subject($ip), self::IP_THRESHOLD);

        return $userLocked || $ipLocked;
    }

    /**
     * Nach erfolgreicher Anmeldung zählt der Benutzername wieder bei null. Die IP nicht:
     * sonst könnte ein Angreifer mit einem eigenen Konto seine Zähler zurücksetzen.
     */
    public function clearUser(string $username): void
    {
        $this->db->execute(
            "DELETE FROM login_failures WHERE scope = 'user' AND subject = :subject",
            ['subject' => self::subject($username)],
        );
    }

    /**
     * Hebt Zähler und Sperre eines Benutzernamens auf (nur durch einen Administrator).
     * Die Sperre der IP bleibt: sie schützt vor Angriffen mit vielen Namen.
     */
    public function unlockUser(string $username): void
    {
        $this->clearUser($username);
    }

    private function bump(string $scope, string $subject, int $threshold): bool
    {
        $now = $this->clock->now();
        $row = $this->db->fetchOne(
            'SELECT failures, last_failure_at FROM login_failures WHERE scope = :scope AND subject = :subject',
            ['scope' => $scope, 'subject' => $subject],
        );

        $failures = 1;
        if ($row !== null && isset($row['failures']) && is_int($row['failures']) && isset($row['last_failure_at']) && is_string($row['last_failure_at'])) {
            $age = $now->getTimestamp() - (new \DateTimeImmutable($row['last_failure_at']))->getTimestamp();
            $failures = $age > self::WINDOW_SECONDS ? 1 : $row['failures'] + 1;
        }

        $lockedUntil = null;
        if ($failures >= $threshold) {
            $seconds = min(self::MAX_LOCK_SECONDS, self::BASE_LOCK_SECONDS * (2 ** ($failures - $threshold)));
            $lockedUntil = $now->modify('+' . $seconds . ' seconds')->format('c');
        }

        $this->db->execute(
            'INSERT INTO login_failures (scope, subject, failures, last_failure_at, locked_until)
             VALUES (:scope, :subject, :failures, :last, :until)
             ON CONFLICT (scope, subject) DO UPDATE SET
                 failures = excluded.failures,
                 last_failure_at = excluded.last_failure_at,
                 locked_until = excluded.locked_until',
            ['scope' => $scope, 'subject' => $subject, 'failures' => $failures, 'last' => $now->format('c'), 'until' => $lockedUntil],
        );

        return $lockedUntil !== null;
    }

    private static function subject(string $value): string
    {
        return hash('sha256', mb_strtolower($value));
    }
}
