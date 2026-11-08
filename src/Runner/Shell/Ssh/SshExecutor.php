<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Shell\Ssh;

use Meridian\Runner\Shell\ExecFailed;
use Meridian\Runner\Shell\ExecSpec;
use Meridian\Runner\Shell\Execution;
use Meridian\Runner\Shell\Executor;
use Meridian\Runner\Shell\LocalExecution;
use Meridian\Runner\Shell\LocalProcessExecutor;
use Meridian\Runner\Shell\ShellTargetKind;
use Meridian\Runner\Shell\SshConnection;
use Meridian\Security\SecretBox;

/**
 * Führt das Skript eines Jobs auf einem Server per SSH aus (Ausführungsort `ssh`).
 *
 * Das Skript läuft **auf dem Server**, nicht im Meridian-Container. Lokal läuft nur der `ssh`-Client:
 *  - `proc_open` mit Argument-Array, nie ein Shell-String; Skript samt `export`-Zeilen über stdin an `sh -s` bzw. `bash -s`.
 *  - Der Server-Schlüssel ist festgehalten: eine eigene `known_hosts` mit genau diesem Schlüssel, `StrictHostKeyChecking=yes`,
 *    nur dieser Schlüsseltyp. Kein Vertrauen beim ersten Verbinden, keine Benutzerkonfiguration (`-F /dev/null`).
 *  - Nur Schlüssel-Anmeldung (`BatchMode`, kein Passwort, kein Agent), keine Weiterleitungen (Agent, X11, Ports), kein Proxy.
 *  - Der private Schlüssel wird erst hier entschlüsselt und liegt nur in einem privaten Arbeitsverzeichnis (0700, Datei 0600),
 *    das {@see LocalExecution::close()} wieder entfernt.
 *  - Umgebung des Kindes genau `PATH`, `LANG`, `HOME` (= Arbeitsverzeichnis); nie geerbt (kein Schlüssel, keine Datenbank).
 *  - Eigene Prozessgruppe wie beim Host-Agenten ({@see LocalProcessExecutor::spawn()}).
 *
 * Grenze: Wird die Verbindung getrennt (Abbruch, Zeitlimit), beendet der Server einen schon gestarteten Befehl nicht
 * zwingend (ohne Terminal kein SIGHUP). Der Lauf weist darauf hin.
 */
final class SshExecutor implements Executor
{
    /** Verbindungsaufbau, danach gelten die Keep-Alive-Werte. */
    public const CONNECT_TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly SecretBox $box,
        private readonly string $workRoot,
        private readonly string $ssh = '/usr/bin/ssh',
        private readonly string $setsid = '/usr/bin/setsid',
        private readonly int $connectTimeout = self::CONNECT_TIMEOUT_SECONDS,
    ) {
    }

    #[\Override]
    public function start(#[\SensitiveParameter] ExecSpec $spec): Execution
    {
        $connection = $spec->ssh;
        if ($connection === null || $spec->target->kind !== ShellTargetKind::Ssh) {
            throw ExecFailed::invalidSpec();
        }
        if (!is_executable($this->ssh) || !is_executable($this->setsid)) {
            throw new ExecFailed(ExecFailed::SSH_NOT_INSTALLED, false);
        }
        try {
            $key = $this->box->decrypt($connection->privateKeyEnc);
        } catch (\Throwable) {
            // Hauptschlüssel falsch oder Wert beschädigt: feste Meldung, nie die der Ausnahme.
            throw new ExecFailed(ExecFailed::SSH_KEY_UNREADABLE, false);
        }
        if (!str_starts_with($key, '-----BEGIN ')) {
            throw new ExecFailed(ExecFailed::SSH_KEY_UNREADABLE, false);
        }
        $workdir = $this->workRoot . '/ssh-' . $spec->runId . '-' . $spec->nonce;
        if (!is_dir($this->workRoot) || !@mkdir($workdir, 0700)) {
            throw new ExecFailed(ExecFailed::START_FAILED, true);
        }

        try {
            $keyFile = $workdir . '/id';
            $knownHosts = $workdir . '/known_hosts';
            $previous = umask(0077);
            try {
                $written = file_put_contents($keyFile, rtrim($key, "\r\n") . "\n") !== false
                    && file_put_contents($knownHosts, $connection->knownHostsLine()) !== false;
            } finally {
                umask($previous);
            }
            unset($key);
            if (!$written || !chmod($keyFile, 0600)) {
                throw new ExecFailed(ExecFailed::START_FAILED, true);
            }

            return LocalProcessExecutor::spawn(
                $this->command($spec, $connection, $keyFile, $knownHosts),
                $workdir,
                ['PATH' => LocalProcessExecutor::PATH, 'LANG' => LocalProcessExecutor::LANG, 'HOME' => $workdir],
                self::preamble($spec) . $spec->script(),
            );
        } catch (\Throwable $e) {
            LocalExecution::removeTree($workdir);
            throw $e instanceof ExecFailed ? $e : new ExecFailed(ExecFailed::START_FAILED, true);
        }
    }

    /**
     * Das Argument-Array für `ssh` — jede Option ein eigenes Element, nie ein zusammengesetzter String.
     *
     * @return list<string>
     */
    public function command(ExecSpec $spec, SshConnection $connection, string $keyFile, string $knownHosts): array
    {
        $options = [
            'IdentitiesOnly=yes',
            'IdentityAgent=none',
            'UserKnownHostsFile=' . $knownHosts,
            'GlobalKnownHostsFile=/dev/null',
            'StrictHostKeyChecking=yes',
            'HostKeyAlgorithms=' . self::hostKeyAlgorithms($connection->hostKeyType()),
            'CheckHostIP=no',
            'UpdateHostKeys=no',
            'BatchMode=yes',
            'PreferredAuthentications=publickey',
            'PasswordAuthentication=no',
            'KbdInteractiveAuthentication=no',
            'ConnectTimeout=' . $this->connectTimeout,
            'ServerAliveInterval=15',
            'ServerAliveCountMax=3',
            'ForwardAgent=no',
            'ForwardX11=no',
            'ClearAllForwardings=yes',
            'PermitLocalCommand=no',
            'ProxyCommand=none',
            'ProxyJump=none',
            'RequestTTY=no',
            'LogLevel=ERROR',
        ];
        $command = [$this->setsid, $this->ssh, '-F', '/dev/null', '-i', $keyFile];
        foreach ($options as $option) {
            $command[] = '-o';
            $command[] = $option;
        }

        return [...$command, '-T', '-p', (string) $connection->port, '-l', $connection->username, '--', $connection->host, $spec->interpreter->value, '-s'];
    }

    /**
     * Erlaubte Server-Schlüsselverfahren: genau das des festgehaltenen Schlüssels (RSA mit den modernen Signaturen).
     */
    private static function hostKeyAlgorithms(string $type): string
    {
        return $type === 'ssh-rsa' ? 'rsa-sha2-512,rsa-sha2-256' : $type;
    }

    /**
     * `export`-Zeilen für die Umgebung des Jobs: `ssh` gibt keine Umgebung weiter, also geht sie als Teil des Skripts mit.
     * Werte stehen in einfachen Anführungszeichen; ein `'` im Wert wird zu `'\''`. Namen sind von {@see ExecSpec} geprüft.
     */
    public static function preamble(#[\SensitiveParameter] ExecSpec $spec): string
    {
        $lines = [];
        $pairs = $spec->env();
        foreach ($spec->systemEnv() as $name => $value) {
            $pairs[] = [$name, $value];
        }
        foreach ($pairs as [$name, $value]) {
            $lines[] = 'export ' . $name . "='" . str_replace("'", "'\\''", $value) . "'";
        }

        return implode("\n", $lines) . "\n";
    }
}
