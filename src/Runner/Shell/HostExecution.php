<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Runner\LiveStream;
use Meridian\Runner\Shell\Agent\AgentSession;
use Meridian\Runner\Shell\Agent\FrameReader;
use Meridian\Runner\Shell\Agent\Frames;
use Meridian\Runner\Shell\Agent\ProtocolError;

/**
 * Ein Lauf im Host-Agenten, gesehen vom Worker: liest `O`/`E`-Blöcke, `P` (Nonce muss stimmen) und `X` (Ende). Abbruch und
 * Zeitlimit gehen als `T`/`K` an die Prozessgruppe des Agenten; {@see self::close()} schließt die Verbindung, worauf der Agent
 * die Gruppe beendet. Ende des Stroms ohne `X` ist ein Fehler (feste Meldung), nie ein stiller Erfolg.
 */
final class HostExecution implements Execution
{
    /** @var resource|null */
    private $stream;

    private readonly FrameReader $reader;

    /** @var list<OutputBlock> */
    private array $queue = [];

    private function hasQueued(): bool
    {
        return $this->queue !== [];
    }

    private function nextQueued(): ?OutputBlock
    {
        return array_shift($this->queue);
    }

    private bool $ended = false;
    private ?int $exitCode = null;
    private ?int $signal = null;
    private bool $closed = false;

    /**
     * @param resource $stream
     */
    public function __construct($stream, private readonly string $nonce)
    {
        $this->stream = $stream;
        $this->reader = new FrameReader($stream, Frames::TO_WORKER);
    }

    #[\Override]
    public function read(float $maxWait): ?OutputBlock
    {
        if ($this->queue !== []) {
            return $this->nextQueued();
        }
        if ($this->ended || $this->stream === null) {
            return null;
        }
        $deadline = microtime(true) + max(0.0, $maxWait);
        do {
            try {
                $frame = $this->reader->read(max(0.0, min($deadline - microtime(true), 0.5)));
            } catch (ProtocolError) {
                throw ExecFailed::hostProtocol();
            }
            if ($frame === false) {
                throw ExecFailed::hostLost();
            }
            if ($frame !== null) {
                $this->handle($frame[0], $frame[1]);
                if ($this->hasQueued()) {
                    return $this->nextQueued();
                }
                if ($this->ended) {
                    return null;
                }
            }
        } while (microtime(true) < $deadline);

        return null;
    }

    #[\Override]
    public function finished(): bool
    {
        return $this->ended && $this->queue === [];
    }

    #[\Override]
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    #[\Override]
    public function termSignal(): ?int
    {
        return $this->signal;
    }

    #[\Override]
    public function terminate(): void
    {
        $this->send('T');
    }

    #[\Override]
    public function kill(): void
    {
        $this->send('K');
    }

    #[\Override]
    public function ref(): ?ExecRef
    {
        return null;
    }

    #[\Override]
    public function notes(): array
    {
        return [];
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $stream = $this->stream;
        $this->stream = null;
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function send(string $type): void
    {
        if ($this->stream === null || $this->ended) {
            return;
        }
        try {
            Frames::write($this->stream, Frames::encode($type), 2.0);
        } catch (ProtocolError) {
            // nie: Typen sind fest
        }
    }

    /**
     * @throws ExecFailed
     */
    private function handle(string $type, #[\SensitiveParameter] string $data): void
    {
        if ($type === 'O' || $type === 'E') {
            for ($offset = 0; $offset < strlen($data); $offset += 65536) {
                $this->queue[] = new OutputBlock($type === 'E' ? LiveStream::Err : LiveStream::Out, substr($data, $offset, 65536));
            }

            return;
        }
        $json = self::decode($data);
        if ($type === 'P') {
            if (($json['nonce'] ?? null) !== $this->nonce) {
                throw ExecFailed::hostProtocol();
            }

            return;
        }
        // X: Ende. Fehlercodes des Agenten auf feste Texte abbilden.
        $error = $json['error'] ?? null;
        if ($error !== null) {
            throw match ($error) {
                AgentSession::ERR_ISOLATION => new ExecFailed(ExecFailed::HOST_ISOLATION, false),
                AgentSession::ERR_GROUP => new ExecFailed(ExecFailed::NO_PROCESS_GROUP, false),
                default => new ExecFailed(ExecFailed::START_FAILED, true),
            };
        }
        /** @var mixed $code */
        $code = $json['exit_code'] ?? null;
        /** @var mixed $signal */
        $signal = $json['signal'] ?? null;
        $this->exitCode = is_int($code) ? $code : null;
        $this->signal = is_int($signal) ? $signal : null;
        $this->ended = true;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ExecFailed
     */
    private static function decode(#[\SensitiveParameter] string $data): array
    {
        try {
            /** @var mixed $json */
            $json = json_decode($data, true, 3, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ExecFailed::hostProtocol();
        }
        if (!is_array($json) || array_is_list($json) && $json !== []) {
            throw ExecFailed::hostProtocol();
        }

        /** @var array<string, mixed> $json */
        return $json;
    }
}
