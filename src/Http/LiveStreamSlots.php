<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;

/**
 * Belegung der Live-Log-Verbindungen (ADR 0004 E9, Tabelle `live_streams`): höchstens `MERIDIAN_LIVE_STREAMS`
 * gleichzeitig und höchstens {@see self::PER_USER} je Benutzer, damit Live-Leser die PHP-Threads nicht erschöpfen.
 *
 * Zählen und Einfügen geschehen in einer `immediate()`-Transaktion (zwei gleichzeitige Anfragen bekommen nie
 * zusammen mehr als die Grenze). Abgelaufene Zeilen zählen nicht mit; der Planer löscht sie (`StaleRuns`). Nur der
 * SSE-Endpunkt schreibt diese Tabelle.
 */
final class LiveStreamSlots
{
    public const PER_USER = 2;
    /** Eine Belegung gilt so lange wie die längste Verbindung ({@see PhpSseChannel::TIME_LIMIT_SECONDS}). */
    public const TTL_SECONDS = PhpSseChannel::TIME_LIMIT_SECONDS;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly int $globalLimit,
    ) {
    }

    /**
     * @return string|null die Kennung der Belegung, null = Grenze erreicht (429)
     */
    public function acquire(int $userId, int $runId): ?string
    {
        return $this->db->immediate(function (Connection $db) use ($userId, $runId): ?string {
            $now = $this->clock->now();
            $nowText = Timestamp::format($now);
            $row = $db->fetchOne(
                'SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN user_id = :u THEN 1 ELSE 0 END), 0) AS mine FROM live_streams WHERE expires_at > :now',
                ['u' => $userId, 'now' => $nowText],
            );
            // Unlesbares Ergebnis zählt als voll (fail-closed).
            $total = self::intOf($row, 'total') ?? PHP_INT_MAX;
            $mine = self::intOf($row, 'mine') ?? PHP_INT_MAX;
            if ($total >= $this->globalLimit || $mine >= self::PER_USER) {
                return null;
            }
            $id = bin2hex(random_bytes(16));
            $db->execute(
                'INSERT INTO live_streams (id, user_id, run_id, expires_at) VALUES (:id, :u, :r, :e)',
                ['id' => $id, 'u' => $userId, 'r' => $runId, 'e' => Timestamp::format($now->modify('+' . self::TTL_SECONDS . ' seconds'))],
            );

            return $id;
        });
    }

    /**
     * Spätestens dann ist eine Belegung frei, die diesen Benutzer jetzt blockiert (für `Retry-After`).
     */
    public function retryAfterSeconds(int $userId): int
    {
        $now = $this->clock->now();
        $nowText = Timestamp::format($now);
        $mine = $this->db->fetchOne('SELECT COUNT(*) AS n, MIN(expires_at) AS first FROM live_streams WHERE user_id = :u AND expires_at > :now', ['u' => $userId, 'now' => $nowText]);
        $row = (self::intOf($mine, 'n') ?? 0) >= self::PER_USER
            ? $mine
            : $this->db->fetchOne('SELECT MIN(expires_at) AS first FROM live_streams WHERE expires_at > :now', ['now' => $nowText]);
        $first = $row === null ? null : ($row['first'] ?? null);
        if (!is_string($first)) {
            return 1;
        }
        $seconds = (new \DateTimeImmutable($first))->getTimestamp() - $now->getTimestamp();

        return max(1, min(self::TTL_SECONDS, $seconds));
    }

    public function release(string $id): void
    {
        $this->db->execute('DELETE FROM live_streams WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function intOf(?array $row, string $key): ?int
    {
        return $row !== null && isset($row[$key]) && is_int($row[$key]) ? $row[$key] : null;
    }
}
