<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Runner\LiveStream;
use Meridian\Security\Sealed;

/**
 * Ein lokal gestarteter Prozess ({@see LocalProcessExecutor}). Schreibt das Skript nicht blockierend über stdin
 * (`stream_select` auf Schreiben) und schließt stdin danach; liest stdout/stderr nicht blockierend in Blöcken
 * ≤ 64 KiB. Signale gehen nur an die eigene Prozessgruppe (`-PGID`), nie an eine einzelne PID oder die des Workers.
 *
 * Endet der Hauptprozess, bekommen übrig gebliebene Mitglieder der Gruppe (`sleep 600 &`) SIGKILL, damit die
 * Ausgaberohre schließen und nichts weiterläuft. {@see self::close()} beendet einen noch laufenden Prozess, reapt
 * ihn (kein Zombie) und entfernt das Arbeitsverzeichnis.
 */
final class LocalExecution implements Execution
{
    public const BLOCK_BYTES = 65536;

    /** So lange wird nach dem Ende des Hauptprozesses noch Ausgabe gelesen. */
    private const DRAIN_SECONDS = 2.0;

    /** @var resource|null */
    private $process;

    /** @var array<int, resource> offene Rohre: 0 = stdin, 1 = stdout, 2 = stderr */
    private array $pipes;

    /** @var Sealed<string> noch nicht geschriebener Teil des Skripts */
    private Sealed $pending;

    /** @var list<OutputBlock> */
    private array $queue = [];

    private bool $exited = false;
    private ?int $exitCode = null;
    private ?int $signal = null;
    private ?float $exitedAt = null;
    private bool $closed = false;
    /** Nach dem Reapen und dem letzten SIGKILL an die Gruppe: keine Signale mehr (die PGID könnte neu vergeben sein). */
    private bool $groupDone = false;

    /**
     * @param resource              $process
     * @param array<array-key, resource> $pipes
     * @param int|null              $pgid    null = keine eigene Gruppe (dann nie Signale an eine Gruppe)
     */
    public function __construct($process, array $pipes, private readonly ?int $pgid, private readonly string $workdir, #[\SensitiveParameter] string $script)
    {
        $this->process = $process;
        $this->pipes = [];
        foreach ($pipes as $i => $pipe) {
            if (is_int($i)) {
                stream_set_blocking($pipe, false);
                $this->pipes[$i] = $pipe;
            }
        }
        $this->pending = new Sealed($script);
        if ($script === '') {
            $this->closePipe(0);
        }
    }

    public function pgid(): ?int
    {
        return $this->pgid;
    }

    #[\Override]
    public function read(float $maxWait): ?OutputBlock
    {
        $block = $this->next();
        if ($block !== null) {
            return $block;
        }
        $deadline = microtime(true) + max(0.0, $maxWait);
        do {
            $this->pump(max(0.0, min($deadline - microtime(true), 1.0)));
            $block = $this->next();
            if ($block !== null) {
                return $block;
            }
            $this->refreshStatus();
            if ($this->exited && (!isset($this->pipes[1]) && !isset($this->pipes[2]))) {
                return null;
            }
            if ($this->exited && $this->exitedAt !== null && microtime(true) - $this->exitedAt > self::DRAIN_SECONDS) {
                // Ein Enkel mit eigener Sitzung hält die Rohre offen: nicht ewig warten.
                $this->closePipe(1);
                $this->closePipe(2);

                return null;
            }
        } while (microtime(true) < $deadline);

        return null;
    }

    #[\Override]
    public function finished(): bool
    {
        $this->refreshStatus();

        return $this->exited && $this->queue === [] && !isset($this->pipes[1]) && !isset($this->pipes[2]);
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
        $this->signalGroup(SIGTERM);
    }

    #[\Override]
    public function kill(): void
    {
        $this->signalGroup(SIGKILL);
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

    /** Läuft der Hauptprozess noch? */
    public function stillRunning(): bool
    {
        $this->refreshStatus();

        return !$this->exited;
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->refreshStatus();
        if (!$this->exited) {
            $this->signalGroup(SIGKILL);
            $deadline = microtime(true) + 5.0;
            while (!$this->exited && microtime(true) < $deadline) {
                usleep(10_000);
                $this->refreshStatus();
            }
        }
        // Reste der Gruppe (Hintergrundprozesse) beenden; danach die Rohre schließen und reapen.
        $this->signalGroup(SIGKILL);
        $this->groupDone = true;
        foreach (array_keys($this->pipes) as $i) {
            $this->closePipe($i);
        }
        if (is_resource($this->process)) {
            proc_close($this->process);
        }
        $this->process = null;
        self::removeTree($this->workdir);
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Entfernt ein Verzeichnis samt Inhalt, folgt nie symbolischen Verknüpfungen. */
    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = @scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    private function next(): ?OutputBlock
    {
        return array_shift($this->queue);
    }

    private function signalGroup(int $signal): void
    {
        if ($this->pgid !== null && $this->pgid > 1 && !$this->groupDone) {
            @posix_kill(-$this->pgid, $signal);
        }
    }

    /** Ein `stream_select`-Durchgang: Skript weiterschreiben, Ausgabe einsammeln. */
    private function pump(float $wait): void
    {
        $read = [];
        foreach ([1, 2] as $i) {
            if (isset($this->pipes[$i])) {
                $read[] = $this->pipes[$i];
            }
        }
        $write = isset($this->pipes[0]) ? [$this->pipes[0]] : [];
        if ($read === [] && $write === []) {
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000.0));
            }

            return;
        }
        $except = null;
        $seconds = (int) floor($wait);
        $micro = (int) (($wait - (float) $seconds) * 1_000_000.0);
        $ready = @stream_select($read, $write, $except, $seconds, $micro);
        if ($ready === false || $ready === 0) {
            return;
        }
        if ($write !== [] && isset($this->pipes[0])) {
            $this->writeScript();
        }
        foreach ([1 => LiveStream::Out, 2 => LiveStream::Err] as $i => $stream) {
            if (!isset($this->pipes[$i]) || !in_array($this->pipes[$i], $read, true)) {
                continue;
            }
            $data = fread($this->pipes[$i], self::BLOCK_BYTES);
            if ($data === false || ($data === '' && feof($this->pipes[$i]))) {
                $this->closePipe($i);
                continue;
            }
            if ($data !== '') {
                $this->queue[] = new OutputBlock($stream, $data);
            }
        }
    }

    private function writeScript(): void
    {
        $pending = $this->pending->open();
        $written = @fwrite($this->pipes[0], substr($pending, 0, self::BLOCK_BYTES));
        if ($written === false || $written === 0) {
            // Der Interpreter liest nicht mehr (beendet): Rest verwerfen.
            $this->pending = new Sealed('');
            $this->closePipe(0);

            return;
        }
        $this->pending = new Sealed(substr($pending, $written));
        if ($written >= strlen($pending)) {
            $this->closePipe(0);
        }
    }

    private function closePipe(int $i): void
    {
        if (isset($this->pipes[$i])) {
            @fclose($this->pipes[$i]);
            unset($this->pipes[$i]);
        }
    }

    private function refreshStatus(): void
    {
        if ($this->exited || !is_resource($this->process)) {
            return;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            return;
        }
        $this->exited = true;
        $this->exitedAt = microtime(true);
        if ($status['signaled']) {
            $this->signal = $status['termsig'];
        } else {
            $this->exitCode = $status['exitcode'];
        }
        // Hauptprozess fertig: übrig gebliebene Mitglieder der Gruppe beenden, damit nichts weiterläuft.
        $this->signalGroup(SIGKILL);
        $this->closePipe(0);
    }
}
