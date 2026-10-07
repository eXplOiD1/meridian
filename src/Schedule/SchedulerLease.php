<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;

/**
 * Sperre gegen einen zweiten Scheduler: eine Zeile in scheduler_lease mit Besitzer und Ablaufzeit.
 *
 * Übernehmen und Verlängern sind dasselbe bedingte UPDATE („abgelaufen oder schon meins“); nur bei
 * genau einer geänderten Zeile gehört die Sperre diesem Prozess. Wer sie nicht hat, plant nichts und
 * übernimmt keine Läufe. Die Besitzer-ID ist pro Prozess neu, damit ein Neustart nie eine fremde Sperre
 * „wiedererkennt“.
 */
final class SchedulerLease
{
    /** Ablauf der Sperre. Deutlich länger als ein Takt, damit Verlängern nie knapp wird. */
    public const TTL_SECONDS = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly string $owner,
    ) {
        if ($owner === '' || strlen($owner) > 200) {
            throw new \InvalidArgumentException('Besitzer-ID der Scheduler-Sperre muss 1 bis 200 Zeichen haben.');
        }
    }

    /**
     * Neue, zufällige Besitzer-ID für einen Prozess (Rechnername und PID nur zur Diagnose).
     */
    public static function newOwnerId(): string
    {
        $host = gethostname();
        $pid = getmypid();

        return sprintf('%s:%d:%s', $host === false ? 'host' : substr($host, 0, 64), $pid === false ? 0 : $pid, bin2hex(random_bytes(8)));
    }

    public function owner(): string
    {
        return $this->owner;
    }

    /**
     * Übernimmt oder verlängert die Sperre. true nur, wenn sie danach diesem Prozess gehört.
     */
    public function acquire(): bool
    {
        $now = $this->clock->now();

        return $this->db->execute(
            'UPDATE scheduler_lease SET owner = :me, expires_at = :until WHERE id = 1 AND (owner = :me OR expires_at <= :now)',
            [
                'me' => $this->owner,
                'until' => Timestamp::format($now->modify('+' . self::TTL_SECONDS . ' seconds')),
                'now' => Timestamp::format($now),
            ],
        ) === 1;
    }

    /**
     * Gehört die Sperre gerade diesem Prozess? Innerhalb einer Transaktion aufrufen, die danach schreibt:
     * dann kann sie zwischen Prüfen und Schreiben niemand übernehmen.
     */
    public function isHeld(): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 AS held FROM scheduler_lease WHERE id = 1 AND owner = :me AND expires_at > :now',
            ['me' => $this->owner, 'now' => Timestamp::format($this->clock->now())],
        ) !== null;
    }

    /**
     * Gibt die Sperre beim Beenden frei, damit ein Nachfolger nicht bis zum Ablauf warten muss.
     */
    public function release(): void
    {
        $this->db->execute(
            'UPDATE scheduler_lease SET owner = :none, expires_at = :epoch WHERE id = 1 AND owner = :me',
            ['none' => '', 'epoch' => Timestamp::EPOCH, 'me' => $this->owner],
        );
    }
}
