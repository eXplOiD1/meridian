<?php

declare(strict_types=1);

namespace Meridian\Auth;

use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Security\SecretMasker;

/**
 * Sitzungen mit Leerlauf- und absoluter Laufzeit. In der Datenbank steht nur der Hash der ID.
 *
 * Für die Sitzungsübersicht (ADR 0005, B8/E10) zusätzlich Browser (`user_agent`, maskiert, ohne Steuerzeichen, auf
 * 120 Zeichen gekürzt — in dieser Reihenfolge) und Client-Adresse (nur eine gültige IP). Beides sieht nur der Inhaber.
 */
final class SessionManager
{
    /** Ohne Anfrage verfällt die Sitzung nach 2 Stunden. */
    public const IDLE_SECONDS = 7200;
    /** Unabhängig von der Aktivität nach spätestens 12 Stunden. */
    public const ABSOLUTE_SECONDS = 43200;
    public const MAX_USER_AGENT = 120;

    private const INSERT_SQL = 'INSERT INTO sessions (token_hash, user_id, created_at, last_seen_at, expires_at, user_agent, client_ip)
      VALUES (:h, :u, :c, :c, :e, :ua, :ip)';

    private const RESOLVE_SQL = 'SELECT s.id, s.user_id, s.last_seen_at, s.expires_at, u.password_must_change
       FROM sessions s JOIN users u ON u.id = s.user_id
      WHERE s.token_hash = :h AND u.is_active = 1 AND u.deleted_at IS NULL';

    private const LIST_SQL = 'SELECT id, created_at, last_seen_at, expires_at, user_agent, client_ip,
            CASE WHEN token_hash = :h THEN 1 ELSE 0 END AS is_current
       FROM sessions WHERE user_id = :u AND expires_at > :now AND last_seen_at > :idle
      ORDER BY last_seen_at DESC, id DESC';

    private const COUNT_SQL = 'SELECT COUNT(*) AS n FROM sessions WHERE user_id = :u AND expires_at > :now AND last_seen_at > :idle';

    private const ORIGIN_SQL = 'SELECT user_agent FROM sessions WHERE token_hash = :h AND user_id = :u';

    private readonly SecretMasker $masker;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        ?SecretMasker $masker = null,
    ) {
        $this->masker = $masker ?? new SecretMasker();
    }

    /**
     * Legt immer eine neue Sitzung mit neuer ID an (kein Übernehmen einer vorhandenen: Session-Fixation).
     */
    public function start(int $userId, ?string $userAgent = null, ?string $clientIp = null): Session
    {
        $now = $this->clock->now();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->db->execute(self::INSERT_SQL, [
            'h' => self::hash($token),
            'u' => $userId,
            'c' => Timestamp::format($now),
            'e' => Timestamp::format($now->modify('+' . self::ABSOLUTE_SECONDS . ' seconds')),
            'ua' => $this->cleanUserAgent($userAgent),
            'ip' => self::cleanIp($clientIp),
        ]);
        $id = $this->db->lastInsertId();
        $this->purgeExpired();

        return new Session($userId, $token, $id);
    }

    /**
     * Liefert die Sitzung nur, wenn sie existiert, nicht abgelaufen ist und der Benutzer aktiv und nicht gelöscht ist.
     * Ein offener Pflicht-Passwortwechsel steht in der Sitzung; `SessionAuth` sperrt damit alles außer
     * `me`/`password`/`logout`.
     */
    public function resolve(#[\SensitiveParameter] string $token): ?Session
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $row = $this->db->fetchOne(self::RESOLVE_SQL, ['h' => self::hash($token)]);
        if ($row === null || !isset($row['id'], $row['user_id'], $row['last_seen_at'], $row['expires_at'], $row['password_must_change'])
            || !is_int($row['id']) || !is_int($row['user_id']) || !is_string($row['last_seen_at']) || !is_string($row['expires_at'])
            || !is_int($row['password_must_change'])) {
            return null;
        }

        $now = $this->clock->now();
        $idleUntil = (new \DateTimeImmutable($row['last_seen_at']))->modify('+' . self::IDLE_SECONDS . ' seconds');
        if ($now >= new \DateTimeImmutable($row['expires_at']) || $now >= $idleUntil) {
            $this->end($token);

            return null;
        }

        $this->db->execute('UPDATE sessions SET last_seen_at = :now WHERE id = :id', ['now' => Timestamp::format($now), 'id' => $row['id']]);

        // Unbekannter Wert ≠ 0: im Zweifel Pflichtwechsel.
        return new Session($row['user_id'], $token, $row['id'], $row['password_must_change'] !== 0);
    }

    public function end(#[\SensitiveParameter] string $token): void
    {
        $this->db->execute('DELETE FROM sessions WHERE token_hash = :h', ['h' => self::hash($token)]);
    }

    /**
     * @return int Anzahl beendeter Sitzungen
     */
    public function endAllForUser(int $userId): int
    {
        return $this->db->execute('DELETE FROM sessions WHERE user_id = :u', ['u' => $userId]);
    }

    /**
     * Beendet eine eigene Sitzung. Die Bedingung `user_id` macht eine fremde Sitzungs-ID wirkungslos (IDOR → 404).
     */
    public function endById(int $userId, int $sessionId): bool
    {
        return $this->db->execute('DELETE FROM sessions WHERE id = :id AND user_id = :u', ['id' => $sessionId, 'u' => $userId]) === 1;
    }

    /**
     * „Alle anderen beenden“: alle Sitzungen des Benutzers außer der übergebenen.
     *
     * @return int Anzahl beendeter Sitzungen
     */
    public function endOthers(int $userId, Session $keep): int
    {
        return $this->db->execute(
            'DELETE FROM sessions WHERE user_id = :u AND token_hash <> :h',
            ['u' => $userId, 'h' => self::hash($keep->token)],
        );
    }

    /**
     * Beendet alle Sitzungen des Benutzers und legt eine neue an, die die aktuelle ersetzt (Passwortwechsel, E10):
     * neuer Token (neues Cookie, neues CSRF-Token), Browser der alten Sitzung, Adresse der aktuellen Anfrage.
     * Der Aufrufer schließt das in eine Transaktion ein.
     */
    public function replaceAll(Session $current, ?string $clientIp): Session
    {
        $row = $this->db->fetchOne(self::ORIGIN_SQL, ['h' => self::hash($current->token), 'u' => $current->userId]);
        $userAgent = $row !== null && isset($row['user_agent']) && is_string($row['user_agent']) ? $row['user_agent'] : null;
        $this->endAllForUser($current->userId);

        return $this->start($current->userId, $userAgent, $clientIp);
    }

    /**
     * Gültige Sitzungen des Benutzers, neueste Aktivität zuerst. `$current` markiert „diese Sitzung“.
     *
     * @return list<SessionView>
     */
    public function listForUser(int $userId, Session $current): array
    {
        $views = [];
        foreach ($this->db->fetchAll(self::LIST_SQL, ['h' => self::hash($current->token), 'u' => $userId] + $this->validity()) as $row) {
            if (!isset($row['id'], $row['created_at'], $row['last_seen_at'], $row['expires_at'], $row['is_current'])
                || !is_int($row['id']) || !is_string($row['created_at']) || !is_string($row['last_seen_at'])
                || !is_string($row['expires_at']) || !is_int($row['is_current'])) {
                continue;
            }
            $views[] = new SessionView(
                $row['id'],
                $row['created_at'],
                $row['last_seen_at'],
                $row['expires_at'],
                $row['is_current'] === 1,
                isset($row['user_agent']) && is_string($row['user_agent']) ? $row['user_agent'] : null,
                isset($row['client_ip']) && is_string($row['client_ip']) ? $row['client_ip'] : null,
            );
        }

        return $views;
    }

    /** Anzahl gültiger Sitzungen (für die Verwaltung: Admins sehen bei Fremden nur die Zahl). */
    public function countForUser(int $userId): int
    {
        $row = $this->db->fetchOne(self::COUNT_SQL, ['u' => $userId] + $this->validity());

        return $row !== null && isset($row['n']) && is_int($row['n']) ? $row['n'] : 0;
    }

    /**
     * Browser-Kennung für die Speicherung: erst maskieren, dann Steuer- und Formatzeichen entfernen, Leerraum
     * zusammenfassen, zuletzt auf 120 Zeichen kürzen (nie umgekehrt). Ungültiges UTF-8 wird ersetzt. Leer → null.
     */
    public function cleanUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }
        // Grobe Obergrenze vor der Regex-Arbeit (Header sind ohnehin begrenzt).
        $text = $this->masker->mask(mb_scrub(substr($userAgent, 0, 2048), 'UTF-8'));
        $text = preg_replace('/[\p{C}\s]+/u', ' ', $text);
        if (!is_string($text)) {
            return null;
        }
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $cut = trim(mb_substr($text, 0, self::MAX_USER_AGENT));

        return $cut === '' ? null : $cut;
    }

    /** Nur eine syntaktisch gültige IP-Adresse wird gespeichert, sonst nichts. */
    public static function cleanIp(?string $ip): ?string
    {
        if ($ip === null || strlen($ip) > 45 || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ip;
    }

    /**
     * @return array{now: string, idle: string}
     */
    private function validity(): array
    {
        $now = $this->clock->now();

        return [
            'now' => Timestamp::format($now),
            'idle' => Timestamp::format($now->modify('-' . self::IDLE_SECONDS . ' seconds')),
        ];
    }

    private function purgeExpired(): void
    {
        $this->db->execute('DELETE FROM sessions WHERE expires_at < :now', ['now' => Timestamp::format($this->clock->now())]);
    }

    private static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
