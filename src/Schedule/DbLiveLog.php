<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Job\Row;
use Meridian\Runner\LiveLog;
use Meridian\Runner\LiveStream;
use Meridian\Runner\Utf8;
use Meridian\Security\SecretMasker;

/**
 * Live-Log eines Laufs in `run_log_chunks` (ADR 0004 E8.4): schreibt nur der Worker. Stücke werden gebündelt
 * (alle {@see self::FLUSH_SECONDS} s oder ab {@see self::FLUSH_BYTES}), vor dem Schreiben **erneut** mit dem Masker
 * des Laufs maskiert und UTF-8-sicher auf höchstens {@see self::CHUNK_BYTES} je Zeile geteilt. Nach
 * {@see LiveLog::MAX_BYTES_PER_RUN} folgt genau ein Abschluss-Eintrag (`sys`), danach nichts mehr. Wirft nie: nach
 * einem Schreibfehler endet nur das Live-Log, nie der Lauf.
 */
final class DbLiveLog implements LiveLog
{
    public const FLUSH_SECONDS = 0.5;
    public const FLUSH_BYTES = 16384;
    public const CHUNK_BYTES = 16384;
    public const NOTE_LIMIT = '[Live-Log nach 1 MiB beendet, die gespeicherte Ausgabe enthält Anfang und Ende.]';

    /** @var list<array{LiveStream, string}> */
    private array $buffer = [];
    private int $buffered = 0;
    private int $written = 0;
    private ?int $seq = null;
    private ?float $lastFlush = null;
    private bool $closed = false;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly int $runId,
        private readonly SecretMasker $masker,
    ) {
    }

    #[\Override]
    public function append(LiveStream $stream, #[\SensitiveParameter] string $maskedText): void
    {
        if ($this->closed || $maskedText === '') {
            return;
        }
        $last = array_key_last($this->buffer);
        if ($last !== null && $this->buffer[$last][0] === $stream) {
            $this->buffer[$last] = [$stream, $this->buffer[$last][1] . $maskedText];
        } else {
            $this->buffer[] = [$stream, $maskedText];
        }
        $this->buffered += strlen($maskedText);
        $now = $this->now();
        $this->lastFlush ??= $now;
        if ($this->buffered >= self::FLUSH_BYTES || $now - $this->lastFlush >= self::FLUSH_SECONDS) {
            $this->flush();
        }
    }

    #[\Override]
    public function flush(): void
    {
        $buffer = $this->buffer;
        $this->buffer = [];
        $this->buffered = 0;
        $this->lastFlush = $this->now();
        if ($this->closed) {
            return;
        }
        if ($buffer === []) {
            return;
        }
        $seq = $this->seq;
        $written = $this->written;
        try {
            // Ein Bündel ganz oder gar nicht: nach einem Fehler bleibt keine halbe Folge stehen.
            $this->closed = $this->db->transaction(function () use ($buffer): bool {
                foreach ($buffer as [$stream, $text]) {
                    $masked = Utf8::scrub($this->masker->mask($text));
                    $room = self::MAX_BYTES_PER_RUN - $this->written;
                    if (strlen($masked) > $room) {
                        $this->insertAll($stream, self::utf8Prefix($masked, $room));
                        $this->insert(LiveStream::Sys, self::NOTE_LIMIT);

                        return true;
                    }
                    $this->insertAll($stream, $masked);
                }

                return false;
            });
        } catch (\Throwable) {
            $this->seq = $seq;
            $this->written = $written;
            // Datenbank gesperrt o. ä.: Live-Log endet, der Lauf läuft weiter (gespeicherte Ausgabe bleibt vollständig).
            $this->closed = true;
        }
    }

    private function insertAll(LiveStream $stream, string $text): void
    {
        while ($text !== '') {
            $piece = self::utf8Prefix($text, self::CHUNK_BYTES);
            if ($piece === '') {
                return;
            }
            $this->insert($stream, $piece);
            $this->written += strlen($piece);
            $text = substr($text, strlen($piece));
        }
    }

    private function insert(LiveStream $stream, string $text): void
    {
        $seq = $this->seq;
        if ($seq === null) {
            $row = $this->db->fetchOne('SELECT COALESCE(MAX(seq), 0) AS seq FROM run_log_chunks WHERE run_id = :run', ['run' => $this->runId]);
            $seq = $row === null ? 0 : (Row::intOrNull($row, 'seq') ?? 0);
        }
        ++$seq;
        $this->db->execute(
            'INSERT INTO run_log_chunks (run_id, seq, stream, data, created_at) VALUES (:run, :seq, :stream, :data, :at)',
            ['run' => $this->runId, 'seq' => $seq, 'stream' => $stream->value, 'data' => $text, 'at' => Timestamp::format($this->clock->now())],
        );
        $this->seq = $seq;
    }

    /** Höchstens `$bytes` Bytes, ohne ein UTF-8-Zeichen zu teilen. */
    private static function utf8Prefix(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }
        $cut = max(0, $bytes);
        while ($cut > 0 && (ord($text[$cut]) & 0xC0) === 0x80) {
            --$cut;
        }

        return substr($text, 0, $cut);
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
