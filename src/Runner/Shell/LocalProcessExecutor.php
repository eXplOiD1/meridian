<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Startet den Interpreter als eigenen Prozess (docs/decisions/0004 §5.4, mer-runner §1) — **nur** im Host-Agenten
 * (S11) und in Tests, nie im Worker und nie im Meridian-Container ({@see HostIsolation}, bei jedem Start geprüft).
 *
 *  - `proc_open([setsid, interpreter, '-s'])` mit Argument-Array, nie ein Shell-String; Skript über stdin.
 *  - Umgebung **genau** `PATH`, `LANG`, `HOME` (= Arbeitsverzeichnis des Laufs), `TZ`, `MERIDIAN_JOB_ID/RUN_ID/TRIGGER`
 *    und die Job-Variablen — nie geerbt (kein `MERIDIAN_KEY_FILE`, kein Datenbankpfad).
 *  - Eigene Prozessgruppe: das Kind von proc_open ist nie Gruppenführer, `setsid` forkt also nicht und PID = PGID.
 *    Geprüft mit `posix_getpgid()`; sonst sofort beenden und {@see ExecFailed::NO_PROCESS_GROUP}.
 *  - Arbeitsverzeichnis je Lauf neu (0700) unter `$workRoot`, danach entfernt.
 */
final class LocalProcessExecutor implements Executor
{
    public const PATH = '/usr/local/bin:/usr/bin:/bin';
    public const LANG = 'C.UTF-8';

    /** So lange darf `setsid` brauchen, bis PID = PGID gilt. */
    private const PGID_WAIT_SECONDS = 2.0;

    /**
     * @param array<string, string> $interpreters absoluter Pfad je {@see ShellInterpreter}
     */
    public function __construct(
        private readonly HostIsolation $isolation,
        private readonly string $workRoot,
        private readonly string $setsid = '/usr/bin/setsid',
        private readonly array $interpreters = ['sh' => '/bin/sh', 'bash' => '/bin/bash'],
    ) {
    }

    #[\Override]
    public function start(#[\SensitiveParameter] ExecSpec $spec): Execution
    {
        $violation = $this->isolation->violation();
        if ($violation !== null) {
            throw new ExecFailed($violation, false);
        }
        $interpreter = $this->interpreters[$spec->interpreter->value] ?? null;
        if ($interpreter === null || !is_executable($this->setsid)) {
            throw new ExecFailed(ExecFailed::START_FAILED, false);
        }
        $workdir = $this->workRoot . '/run-' . $spec->runId . '-' . $spec->nonce;
        if (!is_dir($this->workRoot) || !@mkdir($workdir, 0700)) {
            throw new ExecFailed(ExecFailed::START_FAILED, true);
        }

        return self::spawn([$this->setsid, $interpreter, '-s'], $workdir, self::environment($spec, $workdir), $spec->script());
    }

    /**
     * Shared process-group lifecycle for the systemd agent and the opt-in Docker host bridge.
     * The caller must establish isolation and create its private work directory before calling this.
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    public static function spawn(array $command, string $workdir, array $environment, #[\SensitiveParameter] string $script): LocalExecution
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workdir,
            $environment,
        );
        if (!is_resource($process)) {
            LocalExecution::removeTree($workdir);
            throw new ExecFailed(ExecFailed::START_FAILED, true);
        }
        $status = proc_get_status($process);
        $pid = $status['pid'];

        // Erst wenn setsid die Gruppe hergestellt hat, trifft ein Signal an -PGID nur diesen Lauf.
        $deadline = microtime(true) + self::PGID_WAIT_SECONDS;
        $grouped = false;
        do {
            $pgid = posix_getpgid($pid);
            if ($pgid === $pid) {
                $grouped = true;
                break;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(500);
        } while (microtime(true) < $deadline);

        $execution = new LocalExecution($process, $pipes, $grouped ? $pid : null, $workdir, $script);
        if (!$grouped && $execution->stillRunning()) {
            // Nie die Gruppe des Workers treffen: nur die PID beenden.
            posix_kill($pid, SIGKILL);
            $execution->close();
            throw new ExecFailed(ExecFailed::NO_PROCESS_GROUP, false);
        }

        return $execution;
    }

    /**
     * Die vollständige Umgebung des Kindes (E12). Nie aus `getenv()`.
     *
     * @return array<string, string>
     */
    public static function environment(#[\SensitiveParameter] ExecSpec $spec, string $home): array
    {
        $env = ['PATH' => self::PATH, 'LANG' => self::LANG, 'HOME' => $home] + $spec->systemEnv();
        foreach ($spec->env() as [$name, $value]) {
            // Verbotene Namen (PATH, HOME, TZ, MERIDIAN_* …) hat ShellPayload schon abgelehnt; doppelt hält besser.
            if (ShellRules::envNameProblem($name) === null) {
                $env[$name] = $value;
            }
        }

        return $env;
    }
}
