<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell\Agent;

/**
 * Rahmen des Host-Agent-Protokolls: `[Typ:1][Länge:4 BE][Daten]`, Daten höchstens 1 MiB (ADR 0004 §5.6).
 *
 * Worker → Agent: `S` Auftrag (JSON), `I` Skript-Daten, `T` SIGTERM, `K` SIGKILL.
 * Agent → Worker: `P` Prozessgruppe (JSON), `O` stdout, `E` stderr, `X` Ende (JSON).
 */
final class Frames
{
    public const MAX_DATA = 1_048_576;
    public const TO_AGENT = 'SITK';
    public const TO_WORKER = 'POEX';
    /** Skript und Ausgabe gehen in Stücken dieser Größe über den Draht. */
    public const CHUNK = 65_536;

    private function __construct()
    {
    }

    /**
     * @throws ProtocolError
     */
    public static function encode(string $type, #[\SensitiveParameter] string $data = ''): string
    {
        if (strlen($type) !== 1 || !ctype_upper($type)) {
            throw ProtocolError::because('Ungültiger Rahmentyp.');
        }
        if (strlen($data) > self::MAX_DATA) {
            throw ProtocolError::because('Rahmen zu groß.');
        }

        return $type . pack('N', strlen($data)) . $data;
    }

    /**
     * Schreibt vollständig und wartet bei vollem Puffer; false = Verbindung weg oder Frist abgelaufen.
     *
     * @param resource $stream
     */
    public static function write($stream, #[\SensitiveParameter] string $bytes, float $timeoutSeconds = 30.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $length = strlen($bytes);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($stream, substr($bytes, $offset, self::CHUNK));
            if ($written === false) {
                return false;
            }
            if ($written > 0) {
                $offset += $written;
                continue;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            $read = null;
            $write = [$stream];
            $except = null;
            @stream_select($read, $write, $except, 0, 200_000);
        }

        return true;
    }
}
