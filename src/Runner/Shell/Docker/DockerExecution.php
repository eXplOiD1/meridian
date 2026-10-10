<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell\Docker;

use Meridian\Runner\LiveStream;
use Meridian\Runner\Shell\ExecFailed;
use Meridian\Runner\Shell\ExecRef;
use Meridian\Runner\Shell\Execution;
use Meridian\Runner\Shell\OutputBlock;
use Meridian\Security\Sealed;

/**
 * Ein laufender Docker-Exec (docs/decisions/0004 §5.5) auf dem hochgestuften Strom:
 *
 *  - schreibt das Skript (genau `LEN` Bytes) nicht blockierend und hält die Schreibseite bis zum Ende **offen**
 *    (kein halbes Schließen: der Proxy würde die Verbindung sonst beenden);
 *  - liest den Docker-Mehrfachstrom (`[typ:1][0:3][länge:4 BE][daten]`, typ 1 stdout, 2 stderr, Rahmen ≤ 1 MiB);
 *  - erwartet als erste stderr-Zeile `MERIDIAN-PGID <nonce> <pid> <pgrp>` (≤ 256 Byte, nie ausgegeben), sonst
 *    Protokollfehler; `pid ≠ pgrp` → Signale nur an die PID, mit Notiz;
 *  - nach Strom-Ende `GET /exec/{id}/json` → `ExitCode`; Ende bei `Running: true` → Verbindung abgebrochen.
 *
 * `terminate()`/`kill()` gehen als Kill-Exec an die Gruppe ({@see DockerExecExecutor::signal()}). Kommt der Abbruch,
 * bevor die PGID bekannt ist, wird er nachgeholt, sobald sie eintrifft.
 */
final class DockerExecution implements Execution
{
    public const MAX_FRAME_BYTES = 1048576;
    public const BLOCK_BYTES = 65536;
    public const NOTE_NO_SETSID = 'Container ohne setsid: Kindprozesse werden beim Abbruch eventuell nicht beendet.';
    private const MAX_PGID_LINE = 256;
    /** Nach Strom-Ende so lange auf `Running: false` warten (Docker meldet das Ende etwas später). */
    private const INSPECT_ATTEMPTS = 10;
    private const INSPECT_PAUSE_MICROSECONDS = 100_000;

    /** @var resource|null */
    private $socket;
    /** @var Sealed<string> */
    private Sealed $pending;
    private string $buffer;
    private string $pgidLine = '';
    private bool $pgidSeen = false;
    /** @var list<OutputBlock> */
    private array $queue = [];
    private bool $eof = false;
    private bool $inspected = false;
    /** Docker hat `Running: false` gemeldet: der Befehl ist sicher beendet. */
    private bool $exited = false;
    private ?int $exitCode = null;
    private ?string $pendingSignal = null;
    private bool $closed = false;
    private float $startedAt;
    /** @var list<string> */
    private array $notes = [];

    /**
     * @param resource $socket hochgestufter Strom, nicht blockierend
     */
    public function __construct(
        private readonly DockerProxyClient $proxy,
        $socket,
        string $alreadyRead,
        private ExecRef $ref,
        private readonly string $nonce,
        #[\SensitiveParameter] string $script,
        private readonly float $pgidWaitSeconds = 10.0,
    ) {
        $this->socket = $socket;
        $this->buffer = $alreadyRead;
        $this->pending = new Sealed($script);
        $this->startedAt = microtime(true);
    }

    #[\Override]
    public function read(float $maxWait): ?OutputBlock
    {
        $block = array_shift($this->queue);
        if ($block !== null) {
            return $block;
        }
        $deadline = microtime(true) + max(0.0, $maxWait);
        do {
            $this->parse();
            $block = array_shift($this->queue);
            if ($block !== null) {
                return $block;
            }
            if ($this->eof) {
                $this->finishStream();

                return null;
            }
            if (!$this->pgidSeen && microtime(true) - $this->startedAt > $this->pgidWaitSeconds) {
                throw ExecFailed::protocol();
            }
            $this->pump(max(0.0, min($deadline - microtime(true), 1.0)));
        } while (microtime(true) < $deadline);
        $this->parse();

        return array_shift($this->queue);
    }

    #[\Override]
    public function finished(): bool
    {
        return $this->eof && $this->exited && $this->queue === [];
    }

    #[\Override]
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    #[\Override]
    public function termSignal(): ?int
    {
        return null;
    }

    #[\Override]
    public function terminate(): void
    {
        $this->sendSignal('TERM');
    }

    #[\Override]
    public function kill(): void
    {
        $this->sendSignal('KILL');
    }

    #[\Override]
    public function ref(): ExecRef
    {
        return $this->ref;
    }

    #[\Override]
    public function notes(): array
    {
        return $this->notes;
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (!$this->finished() && $this->ref->pgid !== null) {
            DockerExecExecutor::signal($this->proxy, $this->ref, 'KILL');
        }
        $socket = $this->socket;
        $this->socket = null;
        if (is_resource($socket)) {
            @fclose($socket);
        }
        $this->pending = new Sealed('');
    }

    public function __destruct()
    {
        $this->close();
    }

    private function sendSignal(string $signal): void
    {
        if ($this->finished()) {
            return;
        }
        if ($this->ref->pgid === null) {
            // PGID noch nicht da: nachholen, sobald die Kennung kommt (KILL schlägt TERM).
            if ($this->pendingSignal !== 'KILL') {
                $this->pendingSignal = $signal;
            }

            return;
        }
        DockerExecExecutor::signal($this->proxy, $this->ref, $signal);
    }

    /** Ein `stream_select`-Durchgang: Skript weiterschreiben, Bytes einsammeln. */
    private function pump(float $wait): void
    {
        if (!is_resource($this->socket)) {
            $this->eof = true;

            return;
        }
        $read = [$this->socket];
        $write = $this->pending->open() !== '' ? [$this->socket] : [];
        $except = null;
        $seconds = (int) floor($wait);
        $micro = (int) (($wait - (float) $seconds) * 1_000_000.0);
        $ready = @stream_select($read, $write, $except, $seconds, $micro);
        if ($ready === false || $ready === 0) {
            return;
        }
        if ($write !== []) {
            $pending = $this->pending->open();
            $written = @fwrite($this->socket, substr($pending, 0, self::BLOCK_BYTES));
            if ($written === false) {
                // Gegenseite liest nicht mehr: Rest verwerfen, die Schreibseite bleibt trotzdem offen.
                $this->pending = new Sealed('');
            } elseif ($written > 0) {
                $this->pending = new Sealed(substr($pending, $written));
            }
        }
        // Nicht blockierend: ohne Daten liefert fread '' (und feof erst am echten Ende).
        $data = @fread($this->socket, self::BLOCK_BYTES);
        if ($data === false || ($data === '' && feof($this->socket))) {
            $this->eof = true;
        } else {
            $this->buffer .= $data;
        }
    }

    /** Zerlegt vollständige Rahmen aus dem Puffer in Ausgabeblöcke. */
    private function parse(): void
    {
        while (strlen($this->buffer) >= 8) {
            $type = ord($this->buffer[0]);
            // Länge: 4 Byte Big Endian.
            $length = (ord($this->buffer[4]) << 24) | (ord($this->buffer[5]) << 16) | (ord($this->buffer[6]) << 8) | ord($this->buffer[7]);
            if (!in_array($type, [0, 1, 2], true) || substr($this->buffer, 1, 3) !== "\0\0\0" || $length > self::MAX_FRAME_BYTES) {
                throw ExecFailed::protocol();
            }
            if (strlen($this->buffer) < 8 + $length) {
                if ($this->eof) {
                    throw ExecFailed::connectionLost();
                }

                return;
            }
            $data = substr($this->buffer, 8, $length);
            $this->buffer = substr($this->buffer, 8 + $length);
            if ($type === 2 && !$this->pgidSeen) {
                $data = $this->consumePgidLine($data);
            } elseif (!$this->pgidSeen && $type === 1 && $data !== '') {
                // Vor der Kennung darf nichts kommen (der Wrapper meldet sie, bevor das Skript startet).
                throw ExecFailed::protocol();
            }
            $stream = $type === 2 ? LiveStream::Err : LiveStream::Out;
            if ($data !== '') {
                foreach (str_split($data, self::BLOCK_BYTES) as $part) {
                    $this->queue[] = new OutputBlock($stream, $part);
                }
            }
        }
        if ($this->eof && $this->buffer !== '') {
            throw ExecFailed::connectionLost();
        }
    }

    /** Liest die Kennungszeile vom Anfang von stderr; gibt den Rest zurück. */
    private function consumePgidLine(#[\SensitiveParameter] string $data): string
    {
        $this->pgidLine .= $data;
        $newline = strpos($this->pgidLine, "\n");
        if ($newline === false) {
            if (strlen($this->pgidLine) > self::MAX_PGID_LINE) {
                throw ExecFailed::protocol();
            }

            return '';
        }
        $line = substr($this->pgidLine, 0, $newline);
        $rest = substr($this->pgidLine, $newline + 1);
        $this->pgidLine = '';
        if ($newline > self::MAX_PGID_LINE || preg_match('/^MERIDIAN-PGID ([0-9a-f]{32}) ([0-9]{1,7}) ([0-9]{0,7})$/D', $line, $m) !== 1
            || !hash_equals($this->nonce, $m[1])) {
            throw ExecFailed::protocol();
        }
        $pid = (int) $m[2];
        if ($pid < 2 || $pid > 4194304) {
            throw ExecFailed::protocol();
        }
        $grouped = $m[3] !== '' && (int) $m[3] === $pid;
        if (!$grouped) {
            $this->notes[] = self::NOTE_NO_SETSID;
        }
        $this->ref = $this->ref->withPgid($pid, $grouped);
        $this->pgidSeen = true;
        if ($this->pendingSignal !== null) {
            $signal = $this->pendingSignal;
            $this->pendingSignal = null;
            $this->sendSignal($signal);
        }

        return $rest;
    }

    /** Strom zu Ende: Exit-Code holen. Läuft der Exec noch, ist die Verbindung abgebrochen. */
    private function finishStream(): void
    {
        if ($this->inspected) {
            return;
        }
        for ($i = 0; $i < self::INSPECT_ATTEMPTS; ++$i) {
            $info = $this->proxy->json('GET', '/exec/' . $this->ref->execId . '/json');
            if ($info['status'] !== 200 || $info['json'] === null) {
                throw DockerProxyClient::statusFailure($info['status']);
            }
            if (($info['json']['Running'] ?? true) === false) {
                $this->exitCode = self::intOrNull($info['json']['ExitCode'] ?? null);
                $this->inspected = true;
                $this->exited = true;
                if (!$this->pgidSeen && $this->exitCode !== 126 && $this->exitCode !== 127) {
                    // Ohne Kennung und ohne „Interpreter fehlt“: der Wrapper lief nicht wie erwartet.
                    throw ExecFailed::protocol();
                }

                return;
            }
            usleep(self::INSPECT_PAUSE_MICROSECONDS);
        }
        // Strom zu, Exec läuft weiter: Verbindung verloren. Gruppe beenden, soweit bekannt.
        $this->inspected = true;
        $this->terminateQuietly();
        throw ExecFailed::connectionLost();
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function terminateQuietly(): void
    {
        if ($this->ref->pgid !== null) {
            DockerExecExecutor::signal($this->proxy, $this->ref, 'TERM');
        }
    }
}
