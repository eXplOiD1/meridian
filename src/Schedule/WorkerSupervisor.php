<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Runner\JobType;

/**
 * Aufseher der Worker-Kinder (ADR 0004 E5): startet `$concurrency` Kindprozesse (je ein Lauf zur Zeit) über
 * `proc_open` mit Argument-Array und **expliziter** Umgebung ({@see self::childEnvironment()}), startet abgestürzte
 * Kinder mit wachsendem Abstand neu (1, 2, 4 … 30 s) und reicht SIGTERM an alle weiter (nach
 * {@see self::STOP_GRACE_SECONDS} s SIGKILL). Lädt weder Schlüssel noch Datenbank; gibt nur Nummer, PID und Exit-Code aus.
 */
final class WorkerSupervisor
{
    public const STOP_GRACE_SECONDS = 25;
    public const MAX_BACKOFF_SECONDS = 30;
    /** Läuft ein Kind so lange, gilt es als stabil: der nächste Neustart beginnt wieder bei 1 s. */
    public const STABLE_SECONDS = 60;
    private const POLL_MICROSECONDS = 200_000;

    /** Umgebung jedes Kindes: nur diese Namen, nie Passwörter, Tokens oder andere Variablen des Aufsehers. */
    public const CHILD_ENV = ['PATH', 'TZ', 'LANG', 'MERIDIAN_ENV', 'MERIDIAN_DATA_DIR', 'MERIDIAN_KEY_FILE', 'MERIDIAN_TIMEZONE'];
    /** HTTP-Worker: Namen des Docker-Proxys, die nie erreichbar sein dürfen (InfrastructureTargets). */
    public const CHILD_ENV_HTTP = ['MERIDIAN_DOCKER_PROXY_HOSTS'];
    /** Shell-Worker: Proxy-Socket, Container- und Host-Allowlist (zweite Schicht, keine Geheimnisse). */
    public const CHILD_ENV_SHELL = ['MERIDIAN_DOCKER_PROXY', 'MERIDIAN_SHELL_HOST_SOCKETS'];

    /**
     * @var list<array{process: resource|null, pid: int, started: float, restartAt: float, backoff: int}>
     */
    private array $slots = [];

    /**
     * @param non-empty-list<string>  $command Kind-Befehl als Argument-Array (nie Shell-String)
     * @param array<string, string>   $env     bereits gefilterte Umgebung ({@see self::childEnvironment()})
     * @param \Closure(string): void  $log     nur feste Texte mit Nummer, PID, Exit-Code
     */
    public function __construct(
        private readonly array $command,
        private readonly array $env,
        private readonly int $concurrency,
        private readonly \Closure $log,
    ) {
        if ($concurrency < 1) {
            throw new \InvalidArgumentException('Mindestens ein Worker-Kind.');
        }
    }

    /**
     * Allowlist der Kind-Umgebung je Typ. Werte werden unverändert übernommen, alle anderen Namen fallen weg.
     *
     * @param array<string, string> $env
     *
     * @return array<string, string>
     */
    public static function childEnvironment(array $env, JobType $type): array
    {
        $names = [...self::CHILD_ENV, ...match ($type) {
            JobType::Http => self::CHILD_ENV_HTTP,
            JobType::Shell => self::CHILD_ENV_SHELL,
        }];
        $child = [];
        foreach ($names as $name) {
            if (isset($env[$name])) {
                $child[$name] = $env[$name];
            }
        }

        return $child;
    }

    /**
     * Läuft, bis `$stopRequested` true liefert; beendet dann alle Kinder (SIGTERM, nach der Frist SIGKILL).
     *
     * @param callable(): bool $stopRequested
     */
    public function run(callable $stopRequested): void
    {
        for ($i = 0; $i < $this->concurrency; ++$i) {
            $this->slots[] = ['process' => null, 'pid' => 0, 'started' => 0.0, 'restartAt' => 0.0, 'backoff' => 1];
        }

        try {
            while (!$stopRequested()) {
                foreach (array_keys($this->slots) as $i) {
                    $this->supervise($i);
                }
                usleep(self::POLL_MICROSECONDS);
            }
        } finally {
            $this->stopAll();
        }
    }

    private function supervise(int $i): void
    {
        $slot = $this->slots[$i];
        $now = microtime(true);
        if ($slot['process'] !== null) {
            $status = proc_get_status($slot['process']);
            if ($status['running']) {
                return;
            }
            $code = $status['exitcode'];
            proc_close($slot['process']);
            $backoff = $now - $slot['started'] >= (float) self::STABLE_SECONDS ? 1 : $slot['backoff'];
            ($this->log)(sprintf('Worker-Kind %d (PID %d) beendet mit Exit-Code %d, Neustart in %d s.', $i + 1, $slot['pid'], $code, $backoff));
            $this->slots[$i] = ['process' => null, 'pid' => 0, 'started' => 0.0, 'restartAt' => $now + (float) $backoff, 'backoff' => min(self::MAX_BACKOFF_SECONDS, $backoff * 2)];

            return;
        }
        if ($now < $slot['restartAt']) {
            return;
        }
        $process = proc_open($this->command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, null, $this->env);
        if (!is_resource($process)) {
            ($this->log)(sprintf('Worker-Kind %d konnte nicht gestartet werden, neuer Versuch in %d s.', $i + 1, $slot['backoff']));
            $this->slots[$i] = ['process' => null, 'pid' => 0, 'started' => 0.0, 'restartAt' => $now + (float) $slot['backoff'], 'backoff' => min(self::MAX_BACKOFF_SECONDS, $slot['backoff'] * 2)];

            return;
        }
        $pid = proc_get_status($process)['pid'];
        $this->slots[$i] = ['process' => $process, 'pid' => $pid, 'started' => $now, 'restartAt' => 0.0, 'backoff' => $slot['backoff']];
        ($this->log)(sprintf('Worker-Kind %d gestartet (PID %d).', $i + 1, $pid));
    }

    private function stopAll(): void
    {
        foreach ($this->slots as $slot) {
            if ($slot['process'] !== null && proc_get_status($slot['process'])['running']) {
                proc_terminate($slot['process'], SIGTERM);
            }
        }
        $deadline = microtime(true) + (float) self::STOP_GRACE_SECONDS;
        do {
            $running = 0;
            foreach ($this->slots as $slot) {
                if ($slot['process'] !== null && proc_get_status($slot['process'])['running']) {
                    ++$running;
                }
            }
            if ($running > 0) {
                usleep(self::POLL_MICROSECONDS / 2);
            }
        } while ($running > 0 && microtime(true) < $deadline);

        foreach ($this->slots as $i => $slot) {
            if ($slot['process'] === null) {
                continue;
            }
            if (proc_get_status($slot['process'])['running']) {
                ($this->log)(sprintf('Worker-Kind %d (PID %d) reagiert nicht auf SIGTERM: SIGKILL.', $i + 1, $slot['pid']));
                proc_terminate($slot['process'], SIGKILL);
            }
            proc_close($slot['process']);
        }
        $this->slots = [];
    }
}
