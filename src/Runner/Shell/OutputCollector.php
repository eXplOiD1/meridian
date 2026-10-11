<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Runner\LiveStream;
use Meridian\Runner\Utf8;

/**
 * Gespeicherte Ausgabe eines Laufs (ADR 0004 E8.5, O7): Kopf {@see self::HEAD_BYTES} + Ende {@see self::TAIL_BYTES}
 * des **maskierten** Stroms in Lesereihenfolge, dazwischen `[… N B ausgelassen …]`; stderr-Zeilen mit Präfix `! `.
 * Zählt zusätzlich die gelesenen Rohbytes (`runs.output_bytes`). Speicher bleibt begrenzt (Ende als Ringpuffer).
 * {@see self::text()} ist höchstens {@see \Meridian\Schedule\Worker::MAX_OUTPUT_BYTES} lang und gültiges UTF-8,
 * damit der Worker nie das Ende abschneidet.
 */
final class OutputCollector
{
    public const HEAD_BYTES = 16384;
    /** 48 KiB abzüglich Platz für den Hinweis. */
    public const TAIL_BYTES = 49152 - 128;
    public const ERR_PREFIX = '! ';

    private string $head = '';
    private string $tail = '';
    private int $total = 0;
    private int $rawBytes = 0;

    /** @var array<string, bool> */
    private array $atLineStart = ['out' => true, 'err' => true, 'sys' => true];

    /** Gelesene Rohbytes (vor dem Maskieren) zählen. */
    public function countRaw(int $bytes): void
    {
        $this->rawBytes += max(0, $bytes);
    }

    public function rawBytes(): int
    {
        return $this->rawBytes;
    }

    /**
     * Hängt bereits maskierten Text an.
     */
    public function append(LiveStream $stream, #[\SensitiveParameter] string $maskedText): void
    {
        if ($maskedText === '') {
            return;
        }
        if ($stream === LiveStream::Err) {
            $maskedText = $this->prefixed($maskedText);
        } else {
            $this->atLineStart[$stream->value] = str_ends_with($maskedText, "\n");
        }
        $this->total += strlen($maskedText);

        if (strlen($this->head) < self::HEAD_BYTES) {
            $room = self::HEAD_BYTES - strlen($this->head);
            $this->head .= substr($maskedText, 0, $room);
            $maskedText = substr($maskedText, $room);
        }
        if ($maskedText === '') {
            return;
        }
        $this->tail .= $maskedText;
        if (strlen($this->tail) > 2 * self::TAIL_BYTES) {
            $this->tail = substr($this->tail, -self::TAIL_BYTES);
        }
    }

    /**
     * Kopf + Hinweis + Ende, gültiges UTF-8 (angeschnittene Zeichen an den Schnittkanten werden entfernt).
     */
    public function text(): string
    {
        $tail = strlen($this->tail) > self::TAIL_BYTES ? substr($this->tail, -self::TAIL_BYTES) : $this->tail;
        $omitted = $this->total - strlen($this->head) - strlen($tail);
        if ($omitted <= 0) {
            return Utf8::scrub($this->head . $tail);
        }
        $head = Utf8::trimIncompleteEnd($this->head);
        $tail = (string) preg_replace('/^[\x80-\xBF]+/', '', $tail);
        $omitted = $this->total - strlen($head) - strlen($tail);

        return Utf8::scrub($head . "\n[… " . $omitted . " B ausgelassen …]\n" . $tail);
    }

    private function prefixed(string $text): string
    {
        $out = '';
        $start = 0;
        $length = strlen($text);
        while ($start < $length) {
            if ($this->atLineStart['err']) {
                $out .= self::ERR_PREFIX;
            }
            $newline = strpos($text, "\n", $start);
            $end = $newline === false ? $length : $newline + 1;
            $out .= substr($text, $start, $end - $start);
            $this->atLineStart['err'] = $newline !== false;
            $start = $end;
        }

        return $out;
    }
}
