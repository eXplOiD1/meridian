<?php

declare(strict_types=1);

namespace Meridian\Auth;

use Meridian\Database\Connection;

/**
 * Sitzungen mit Leerlauf- und absoluter Laufzeit. In der Datenbank steht nur der Hash der ID.
 */
final class SessionManager
{
    /** Ohne Anfrage verfällt die Sitzung nach 2 Stunden. */
    public const IDLE_SECONDS = 7200;
    /** Unabhängig von der Aktivität nach spätestens 12 Stunden. */
    public const ABSOLUTE_SECONDS = 43200;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Legt immer eine neue Sitzung mit neuer ID an (kein Übernehmen einer vorhandenen: Session-Fixation).
     */
    public function start(int $userId): Session
    {
        $now = $this->clock->now();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->db->execute(
            'INSERT INTO sessions (token_hash, user_id, created_at, last_seen_at, expires_at) VALUES (:h, :u, :c, :c, :e)',
            [
                'h' => self::hash($token),
                'u' => $userId,
                'c' => $now->format('c'),
                'e' => $now->modify('+' . self::ABSOLUTE_SECONDS . ' seconds')->format('c'),
            ],
        );
        $this->purgeExpired();

        return new Session($userId, $token);
    }

    /**
     * Liefert die Sitzung nur, wenn sie existiert, nicht abgelaufen ist und der Benutzer aktiv ist.
     */
    public function resolve(#[\SensitiveParameter] string $token): ?Session
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT s.id, s.user_id, s.last_seen_at, s.expires_at
               FROM sessions s JOIN users u ON u.id = s.user_id
              WHERE s.token_hash = :h AND u.is_active = 1',
            ['h' => self::hash($token)],
        );
        if ($row === null || !isset($row['id'], $row['user_id'], $row['last_seen_at'], $row['expires_at'])
            || !is_int($row['id']) || !is_int($row['user_id']) || !is_string($row['last_seen_at']) || !is_string($row['expires_at'])) {
            return null;
        }

        $now = $this->clock->now();
        $idleUntil = (new \DateTimeImmutable($row['last_seen_at']))->modify('+' . self::IDLE_SECONDS . ' seconds');
        if ($now >= new \DateTimeImmutable($row['expires_at']) || $now >= $idleUntil) {
            $this->end($token);

            return null;
        }

        $this->db->execute('UPDATE sessions SET last_seen_at = :now WHERE id = :id', ['now' => $now->format('c'), 'id' => $row['id']]);

        return new Session($row['user_id'], $token);
    }

    public function end(#[\SensitiveParameter] string $token): void
    {
        $this->db->execute('DELETE FROM sessions WHERE token_hash = :h', ['h' => self::hash($token)]);
    }

    public function endAllForUser(int $userId): void
    {
        $this->db->execute('DELETE FROM sessions WHERE user_id = :u', ['u' => $userId]);
    }

    private function purgeExpired(): void
    {
        $this->db->execute('DELETE FROM sessions WHERE expires_at < :now', ['now' => $this->clock->now()->format('c')]);
    }

    private static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
