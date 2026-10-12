<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Shell\Agent;

use Meridian\Runner\LiveStream;
use Meridian\Runner\Shell\ExecFailed;
use Meridian\Runner\Shell\Executor;
use Meridian\Runner\Shell\HostIsolation;
use Meridian\Runner\Shell\Execution;

/**
 * Eine Verbindung = ein Lauf (ADR 0004 E3/§5.6, ADR 0014). Läuft im Host-Agenten (`bin/meridian-shell-agent`, eigener
 * Benutzer, ohne Datenbank und Schlüssel): liest den Auftrag, prüft ihn erneut, startet den Interpreter über den lokalen
 * {@see Executor}, reicht die Ausgabe gerahmt zurück und beendet am Ende die Prozessgruppe.
 *
 * Schließt der Worker die Verbindung oder verletzt er das Protokoll, wird die ganze Prozessgruppe beendet (SIGKILL) und die
 * Sitzung endet; nichts läuft weiter. Meldungen im Fehlerfall sind feste Texte (`$log`), nie Skript, Werte oder Ausgabe.
 */
final class AgentSession
{
    /** So lange darf der Worker für Auftrag und Skript brauchen, bevor die Verbindung verworfen wird. */
    public const REQUEST_SECONDS = 10.0;
    /** Taktung der Schleife: so schnell reagiert der Agent auf Abbruch und Ende der Verbindung. */
    private const POLL_SECONDS = 0.2;

    /** Fehlercodes im Rahmen `X`, vom Worker auf feste Texte abgebildet. */
    public const ERR_ISOLATION = 'isolation';
    public const ERR_START = 'start';
    public const ERR_GROUP = 'group';

    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /**
     * @param \Closure(string): void|null $log nimmt feste Texte für das Journal (Standard: nichts)
     */
    public function __construct(private readonly Executor $executor, ?\Closure $log = null)
    {
        $this->log = $log ?? static function (string $text): void {
        };
    }

    /**
     * @param resource $in
     * @param resource $out
     *
     * @return int 0 = Lauf behandelt (auch bei Abbruch durch den Worker), 1 = Protokollfehler, nichts gestartet
     */
    public function run($in, $out): int
    {
        $reader = new FrameReader($in, Frames::TO_AGENT);
        try {
            $request = $this->readRequest($reader);
            $script = $request === null ? null : $this->readScript($reader, $request->scriptBytes);
            if ($request === null || $script === null) {
                ($this->log)('Verbindung geschlossen, bevor der Auftrag vollständig war.');

                return 1;
            }
            $spec = $request->toSpec($script);
        } catch (ProtocolError $e) {
            ($this->log)($e->getMessage());

            return 1;
        }

        try {
            $execution = $this->executor->start($spec);
        } catch (ExecFailed $e) {
            ($this->log)('Start verweigert.');
            Frames::write($out, Frames::encode('X', json_encode(['exit_code' => null, 'signal' => null, 'error' => self::codeOf($e)], JSON_THROW_ON_ERROR)));

            return 0;
        }

        try {
            $this->relay($execution, $reader, $out, $request->nonce);
        } finally {
            $execution->close();
        }

        return 0;
    }

    /**
     * @throws ProtocolError
     */
    private function readRequest(FrameReader $reader): ?AgentRequest
    {
        $frame = self::next($reader, self::REQUEST_SECONDS);
        if ($frame === null) {
            return null;
        }
        if ($frame[0] !== 'S') {
            throw ProtocolError::because('Erwartet wird zuerst der Auftrag.');
        }

        return AgentRequest::parse($frame[1]);
    }

    /**
     * @throws ProtocolError
     */
    private function readScript(FrameReader $reader, int $bytes): ?string
    {
        $script = '';
        $deadline = microtime(true) + self::REQUEST_SECONDS;
        while (strlen($script) < $bytes) {
            $frame = self::next($reader, max(0.0, $deadline - microtime(true)));
            if ($frame === null) {
                return null;
            }
            if ($frame[0] !== 'I') {
                throw ProtocolError::because('Erwartet werden Skript-Daten.');
            }
            $script .= $frame[1];
            if (strlen($script) > $bytes) {
                throw ProtocolError::because('Mehr Skript-Daten als angekündigt.');
            }
        }

        return $script;
    }

    /**
     * @return array{string, string}|null null = Ende des Stroms oder Frist abgelaufen
     *
     * @throws ProtocolError
     */
    private static function next(FrameReader $reader, float $seconds): ?array
    {
        $deadline = microtime(true) + $seconds;
        do {
            $frame = $reader->read(min(0.5, max(0.0, $deadline - microtime(true))));
            if ($frame === false) {
                return null;
            }
            if ($frame !== null) {
                return $frame;
            }
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * @param resource $out
     */
    private function relay(Execution $execution, FrameReader $reader, $out, string $nonce): void
    {
        $pgid = $execution instanceof \Meridian\Runner\Shell\LocalExecution ? $execution->pgid() : null;
        if (!Frames::write($out, Frames::encode('P', json_encode(['nonce' => $nonce, 'pgid' => $pgid], JSON_THROW_ON_ERROR)))) {
            $execution->kill();

            return;
        }
        while (true) {
            try {
                $block = $execution->read(self::POLL_SECONDS);
            } catch (ExecFailed) {
                ($this->log)('Lauf gestört, beendet.');
                $execution->kill();

                return;
            }
            if ($block !== null) {
                $bytes = $block->bytes();
                $type = $block->stream === LiveStream::Err ? 'E' : 'O';
                for ($offset = 0; $offset < strlen($bytes); $offset += Frames::CHUNK) {
                    if (!Frames::write($out, Frames::encode($type, substr($bytes, $offset, Frames::CHUNK)))) {
                        ($this->log)('Worker nicht mehr erreichbar, Lauf beendet.');
                        $execution->kill();

                        return;
                    }
                }
            }
            try {
                $frame = $reader->read(0.0);
            } catch (ProtocolError $e) {
                ($this->log)($e->getMessage());
                $execution->kill();

                return;
            }
            if ($frame === false) {
                ($this->log)('Worker hat die Verbindung geschlossen, Lauf beendet.');
                $execution->kill();

                return;
            }
            if ($frame !== null) {
                if ($frame[0] === 'T') {
                    $execution->terminate();
                } elseif ($frame[0] === 'K') {
                    $execution->kill();
                } else {
                    ($this->log)('Unerwarteter Rahmen im Lauf, beendet.');
                    $execution->kill();

                    return;
                }
            }
            if ($block === null && $execution->finished()) {
                Frames::write($out, Frames::encode('X', json_encode(['exit_code' => $execution->exitCode(), 'signal' => $execution->termSignal(), 'error' => null], JSON_THROW_ON_ERROR)));

                return;
            }
        }
    }

    private static function codeOf(ExecFailed $e): string
    {
        return match ($e->getMessage()) {
            HostIsolation::IN_CONTAINER, HostIsolation::AS_ROOT, HostIsolation::CAN_READ_SECRETS => self::ERR_ISOLATION,
            ExecFailed::NO_PROCESS_GROUP => self::ERR_GROUP,
            default => self::ERR_START,
        };
    }
}
