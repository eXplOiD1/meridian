<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);

namespace Meridian\Shell;

/**
 * Ruft den öffentlichen Host-Schlüssel eines SSH-Servers ab (`ssh-keyscan`), damit er im Formular nicht von Hand
 * kopiert werden muss. Der Abruf ist nur so sicher wie das Netz dazwischen: Die Oberfläche zeigt deshalb den
 * Fingerabdruck, den man auf dem Server gegenprüft. Aufgerufen nur mit dem Recht `shell.targets`.
 */
final class SshHostKeyScanner
{
    private const BINARIES = ['/usr/bin/ssh-keyscan', '/bin/ssh-keyscan'];
    private const MAX_OUTPUT = 16384;
    private const PROCESS_SECONDS = 10;
    /** Bevorzugte Reihenfolge, wenn der Server mehrere Schlüssel anbietet. */
    private const PREFERENCE = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa'];

    /**
     * @return array{host_key: string, fingerprint: string, type: string}
     *
     * @throws InvalidShellTarget Host oder Port ungültig
     * @throws SshHostKeyScanFailed kein Client, nicht erreichbar oder keine Antwort
     */
    public function scan(string $host, int $port): array
    {
        if (!SshProfile::isValidHost($host)) {
            throw new InvalidShellTarget('host', 'Hostname oder IP-Adresse ohne URL, Benutzer oder Optionen angeben.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidShellTarget('port', 'Port: ganze Zahl zwischen 1 und 65535.');
        }
        $binary = null;
        foreach (self::BINARIES as $candidate) {
            if (is_executable($candidate)) {
                $binary = $candidate;
                break;
            }
        }
        if ($binary === null) {
            throw new SshHostKeyScanFailed('Auf diesem Rechner fehlt der SSH-Client (openssh-client); der Schlüssel lässt sich nicht abrufen. Bitte von Hand eintragen.', 503);
        }

        // Argument-Array ohne Shell, explizite Umgebung; der Host beginnt nie mit „-“ (siehe isValidHost).
        $process = @proc_open(
            [$binary, '-T', '6', '-t', 'ed25519,ecdsa,rsa', '-p', (string) $port, '--', $host],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PATH' => '/usr/bin:/bin', 'LANG' => 'C'],
        );
        if (!is_resource($process)) {
            throw new SshHostKeyScanFailed('Der Schlüsselabruf konnte nicht gestartet werden.', 502);
        }
        $output = '';
        $deadline = microtime(true) + self::PROCESS_SECONDS;
        stream_set_blocking($pipes[1], false);
        while (microtime(true) < $deadline && strlen($output) < self::MAX_OUTPUT) {
            $chunk = fread($pipes[1], 4096);
            if ($chunk !== false && $chunk !== '') {
                $output .= $chunk;
                continue;
            }
            if (feof($pipes[1])) {
                break;
            }
            usleep(50000);
        }
        fclose($pipes[1]);
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);

        $found = self::parse($output);
        if ($found === null) {
            throw new SshHostKeyScanFailed('Der Server hat keinen Schlüssel geliefert. Erreichbarkeit, Port und SSH-Dienst prüfen (der Abruf kommt von Meridian aus, nicht von deinem Rechner).', 502);
        }

        return $found;
    }

    /**
     * Wählt aus der Ausgabe von `ssh-keyscan` den bevorzugten Schlüssel; Zeilen mit „#“ und alles Ungültige fallen weg.
     *
     * @return array{host_key: string, fingerprint: string, type: string}|null
     */
    public static function parse(string $output): ?array
    {
        $keys = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $parts = explode(' ', trim($line));
            if (count($parts) < 3 || str_starts_with($parts[0], '#') || !in_array($parts[1], self::PREFERENCE, true) || preg_match('/^[A-Za-z0-9+\/=]+$/D', $parts[2]) !== 1) {
                continue;
            }
            $blob = base64_decode($parts[2], true);
            if ($blob === false || strlen($blob) < 8 || substr($blob, 0, 4) !== pack('N', strlen($parts[1])) || substr($blob, 4, strlen($parts[1])) !== $parts[1]) {
                continue;
            }
            $keys[$parts[1]] = ['host_key' => $parts[1] . ' ' . $parts[2], 'fingerprint' => 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '='), 'type' => $parts[1]];
        }
        foreach (self::PREFERENCE as $type) {
            if (isset($keys[$type])) {
                return $keys[$type];
            }
        }

        return null;
    }
}
