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
    /** Hinter einem Reverse-Proxy ohne MERIDIAN_TRUSTED_PROXIES teilen sich alle dieselbe IP: bewusst großzügig. */
    private const IP_THRESHOLD = 50;
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

    /**
     * Hebt die Sperre einer Client-IP auf (Administrator oder Betreiber an der Befehlszeile).
     */
    public function unlockIp(string $ip): void
    {
        $this->db->execute(
            "DELETE FROM login_failures WHERE scope = 'ip' AND subject = :subject",
            ['subject' => self::subject($ip)],
        );
    }

    /**
     * Zählt atomar in einer einzigen Anweisung: parallele Anfragen überschreiben sich nicht gegenseitig,
     * jede bekommt ihren eigenen Zählerstand zurück.
     */
    private function bump(string $scope, string $subject, int $threshold): bool
    {
        $now = $this->clock->now();
        $row = $this->db->fetchOne(
            'INSERT INTO login_failures (scope, subject, failures, last_failure_at, locked_until)
             VALUES (:scope, :subject, 1, :now, NULL)
             ON CONFLICT (scope, subject) DO UPDATE SET
                 failures = CASE WHEN login_failures.last_failure_at < :window THEN 1 ELSE login_failures.failures + 1 END,
                 last_failure_at = :now
             RETURNING failures',
            [
                'scope' => $scope,
                'subject' => $subject,
                'now' => $now->format('c'),
                'window' => $now->modify('-' . self::WINDOW_SECONDS . ' seconds')->format('c'),
            ],
        );
        $failures = isset($row['failures']) && is_int($row['failures']) ? $row['failures'] : 1;
        if ($failures < $threshold) {
            return false;
        }

        // Bit-Verschiebung statt Potenz: bleibt ein int (30, 60, 120 ...), gedeckelt auf MAX_LOCK_SECONDS.
        $seconds = min(self::MAX_LOCK_SECONDS, self::BASE_LOCK_SECONDS << min($failures - $threshold, 10));
        // Nie eine kürzere Sperre über eine längere schreiben, die eine parallele Anfrage gerade gesetzt hat.
        $this->db->execute(
            'UPDATE login_failures
                SET locked_until = CASE WHEN locked_until IS NULL OR locked_until < :until THEN :until ELSE locked_until END
              WHERE scope = :scope AND subject = :subject',
            ['scope' => $scope, 'subject' => $subject, 'until' => $now->modify('+' . $seconds . ' seconds')->format('c')],
        );

        return true;
    }

    private static function subject(string $value): string
    {
        return hash('sha256', mb_strtolower($value));
    }
}
