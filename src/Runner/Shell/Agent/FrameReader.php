<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Shell\Agent;

/**
 * Liest Rahmen nicht blockierend aus einem Strom. Ein unerlaubter Typ oder eine zu große Länge ist ein Protokollfehler
 * (sofort, ohne die Daten zu lesen); das Ende des Stroms mitten in einem Rahmen zählt wie ein Ende (false).
 */
final class FrameReader
{
    private string $buffer = '';

    /**
     * @param resource $stream
     * @param string   $allowed erlaubte Typen, z. B. {@see Frames::TO_AGENT}
     */
    public function __construct(private $stream, private readonly string $allowed)
    {
        stream_set_blocking($this->stream, false);
    }

    /**
     * @return array{string, string}|null|false null = noch nichts Vollständiges, false = Ende des Stroms
     *
     * @throws ProtocolError
     */
    public function read(float $wait = 0.0): array|null|false
    {
        $frame = $this->take();
        if ($frame !== null) {
            return $frame;
        }
        $deadline = microtime(true) + max(0.0, $wait);
        do {
            $remaining = max(0.0, $deadline - microtime(true));
            $read = [$this->stream];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - floor($remaining)) * 1_000_000.0));
            if ($ready !== false && $ready > 0) {
                $chunk = @fread($this->stream, Frames::CHUNK);
                if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                    return false;
                }
                $this->buffer .= $chunk;
                $frame = $this->take();
                if ($frame !== null) {
                    return $frame;
                }
            }
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * @return array{string, string}|null
     *
     * @throws ProtocolError
     */
    private function take(): ?array
    {
        if (strlen($this->buffer) < 5) {
            return null;
        }
        $type = $this->buffer[0];
        if (!str_contains($this->allowed, $type)) {
            throw ProtocolError::because('Unerwarteter Rahmentyp.');
        }
        $unpacked = unpack('Nlength', substr($this->buffer, 1, 4));
        $length = -1;
        if (is_array($unpacked)) {
            /** @var mixed $candidate */
            $candidate = $unpacked['length'] ?? null;
            $length = is_int($candidate) ? $candidate : -1;
        }
        if ($length < 0 || $length > Frames::MAX_DATA) {
            throw ProtocolError::because('Rahmen zu groß.');
        }
        if (strlen($this->buffer) < 5 + $length) {
            return null;
        }
        $data = substr($this->buffer, 5, $length);
        $this->buffer = substr($this->buffer, 5 + $length);

        return [$type, $data];
    }
}
