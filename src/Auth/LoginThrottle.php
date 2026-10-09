<?php

declare(strict_types=1);

namespace Meridian\Auth;

use Meridian\Database\Connection;

/**
 * Eskalierende Sperre nach Fehlversuchen, getrennt nach Benutzername und Client-IP.
 *
 * Unbekannte Benutzernamen werden genauso gezählt wie bekannte, damit die Sperre nichts verrät.
 * Gespeichert wird nur ein Hash des Namens bzw. der IP.
 *
 * Ein Versuch wird VOR der Passwortprüfung reserviert ({@see reserve()}): Prüfen der Sperre und Zählen
 * geschehen in einer einzigen sofort schreibenden Transaktion. So werden pro Fenster nie mehr Passwörter
 * geprüft, als die Schwelle erlaubt, egal wie viele Anfragen gleichzeitig kommen.
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

        return max($this->remainingLock('user', self::subject($username), $now), $this->remainingLock('ip', self::subject($ip), $now));
    }

    /**
     * Ist dieser Benutzername gerade gesperrt? Nur die Sperre des Namens, nicht die einer IP (für die Verwaltung:
     * „gesperrt“-Markierung in der Benutzerliste).
     */
    public function isUserLocked(string $username): bool
    {
        return $this->remainingLock('user', self::subject($username), $this->clock->now()) > 0;
    }

    /**
     * Reserviert einen Versuch. Ist Konto oder IP gerade gesperrt, wird er abgewiesen und nichts gezählt.
     * Sonst wird er gezählt und darf geprüft werden; erreicht er die Schwelle, beginnt (oder verlängert sich) die
     * Sperre, der Versuch selbst wird aber noch geprüft. Nach Ablauf der Sperre gibt es so genau einen weiteren
     * Versuch, bei Misserfolg mit längerer Sperre.
     *
     * Wer sich erfolgreich anmeldet (oder nur den zweiten Faktor noch schuldet), gibt den Platz mit
     * {@see release()} wieder frei. Ein Fehlversuch behält ihn.
     */
    public function reserve(string $username, string $ip): Reservation
    {
        return $this->db->immediate(function () use ($username, $ip): Reservation {
            $now = $this->clock->now();
            $user = self::subject($username);
            $ipHash = self::subject($ip);

            $remaining = max($this->remainingLock('user', $user, $now), $this->remainingLock('ip', $ipHash, $now));
            if ($remaining > 0) {
                return Reservation::refused($remaining);
            }

            $userReached = $this->increment('user', $user, self::USER_THRESHOLD, $now);
            $ipReached = $this->increment('ip', $ipHash, self::IP_THRESHOLD, $now);

            return Reservation::allowed($userReached || $ipReached);
        });
    }

    /**
     * Gibt einen reservierten Versuch zurück (Anmeldung gelungen). Der Benutzername zählt wieder bei null,
     * die IP nur um einen Versuch weniger: sonst könnte ein Angreifer mit einem eigenen Konto seinen
     * IP-Zähler zurücksetzen.
     */
    public function release(string $username, string $ip): void
    {
        $this->clearUser($username);
        $this->db->execute(
            "UPDATE login_failures SET failures = CASE WHEN failures > 0 THEN failures - 1 ELSE 0 END WHERE scope = 'ip' AND subject = :subject",
            ['subject' => self::subject($ip)],
        );
    }

    /**
     * Zählt einen Fehlversuch ohne Prüfung der Sperre (für Tests und Sonderfälle).
     *
     * @return bool true, wenn dieser Versuch die Schwelle erreicht oder überschritten hat
     */
    public function recordFailure(string $username, string $ip): bool
    {
        return $this->db->immediate(function () use ($username, $ip): bool {
            $now = $this->clock->now();
            $userReached = $this->increment('user', self::subject($username), self::USER_THRESHOLD, $now);
            $ipReached = $this->increment('ip', self::subject($ip), self::IP_THRESHOLD, $now);

            return $userReached || $ipReached;
        });
    }

    /**
     * Nach erfolgreicher Anmeldung zählt der Benutzername wieder bei null.
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

    private function remainingLock(string $scope, string $subject, \DateTimeImmutable $now): int
    {
        $row = $this->db->fetchOne(
            'SELECT locked_until FROM login_failures WHERE scope = :scope AND subject = :subject',
            ['scope' => $scope, 'subject' => $subject],
        );
        if ($row === null || !isset($row['locked_until']) || !is_string($row['locked_until'])) {
            return 0;
        }

        $until = new \DateTimeImmutable($row['locked_until']);

        return $until > $now ? $until->getTimestamp() - $now->getTimestamp() : 0;
    }

    /**
     * Zählt einen Versuch hoch und setzt bei Erreichen der Schwelle die (wachsende) Sperre.
     * Nur innerhalb einer sofort schreibenden Transaktion aufrufen.
     *
     * @return bool true, wenn dieser Versuch die Schwelle erreicht oder überschritten hat
     */
    private function increment(string $scope, string $subject, int $threshold, \DateTimeImmutable $now): bool
    {
        $row = $this->db->fetchOne(
            'SELECT failures, last_failure_at, locked_until FROM login_failures WHERE scope = :scope AND subject = :subject',
            ['scope' => $scope, 'subject' => $subject],
        );

        $failures = 1;
        if ($row !== null && isset($row['failures'], $row['last_failure_at']) && is_int($row['failures']) && is_string($row['last_failure_at'])) {
            $age = $now->getTimestamp() - (new \DateTimeImmutable($row['last_failure_at']))->getTimestamp();
            $failures = $age > self::WINDOW_SECONDS ? 1 : $row['failures'] + 1;
        }

        $lockedUntil = null;
        if ($failures >= $threshold) {
            // Bit-Verschiebung statt Potenz: bleibt ein int (30, 60, 120 ...), gedeckelt auf MAX_LOCK_SECONDS.
            $seconds = min(self::MAX_LOCK_SECONDS, self::BASE_LOCK_SECONDS << min($failures - $threshold, 10));
            $lockedUntil = $now->modify('+' . $seconds . ' seconds')->format('c');
            // Eine bestehende, längere Sperre wird nie durch eine kürzere ersetzt.
            if ($row !== null && isset($row['locked_until']) && is_string($row['locked_until']) && $row['locked_until'] > $lockedUntil) {
                $lockedUntil = $row['locked_until'];
            }
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
