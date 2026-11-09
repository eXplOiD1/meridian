<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);

namespace Meridian\Shell;

use Meridian\Security\Sealed;

/** Validated SSH endpoint; private key material never participates in JSON or debug output. */
final class SshProfile
{
    /** @var Sealed<string>|null */
    private readonly ?Sealed $privateKey;
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $hostKey,
        #[\SensitiveParameter] ?string $privateKey,
    ) {
        $this->privateKey = $privateKey === null ? null : new Sealed($privateKey);
    }
    /** @param array<mixed> $data */
    public static function validate(#[\SensitiveParameter] array $data): self
    {
        if (array_diff(array_keys($data), ['host','port','username','host_key','private_key']) !== []) {
            throw new InvalidShellTarget('ssh', 'Unbekanntes SSH-Feld.');
        }
        $host = $data['host'] ?? null;
        $port = $data['port'] ?? 22;
        $user = $data['username'] ?? null;
        $hostKey = $data['host_key'] ?? null;
        $key = $data['private_key'] ?? null;
        if (!is_string($host) || strlen($host) > 253 || $host === '' || $host !== trim($host)
            || (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', $host) !== 1)
            || str_contains($host, '..')) {
            throw new InvalidShellTarget('ssh.host', 'SSH-Server: Hostname oder IP-Adresse ohne URL, Benutzer oder Optionen angeben.');
        }
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new InvalidShellTarget('ssh.port', 'SSH-Port: ganze Zahl zwischen 1 und 65535.');
        }
        if (!is_string($user) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,31}$/D', $user) !== 1) {
            throw new InvalidShellTarget('ssh.username', 'Gültigen SSH-Benutzernamen angeben.');
        }
        if (!is_string($hostKey) || strlen($hostKey) > 8192 || preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) ([A-Za-z0-9+\/=]+)(?: [^\r\n\0]{0,200})?$/D', trim($hostKey), $parts) !== 1
            || base64_decode($parts[2], true) === false) {
            throw new InvalidShellTarget('ssh.host_key', 'Den geprüften öffentlichen Server-Schlüssel als „ssh-ed25519 AAAA…“ (oder RSA/ECDSA) angeben.');
        }
        $blob = base64_decode($parts[2], true);
        if (strlen($blob) < 8 || substr($blob, 4, strlen($parts[1])) !== $parts[1] || substr($blob, 0, 4) !== pack('N', strlen($parts[1]))) {
            throw new InvalidShellTarget('ssh.host_key', 'Ungültiger öffentlicher SSH-Server-Schlüssel.');
        }
        if ($key !== null && (!is_string($key) || strlen($key) > 65536 || str_contains($key, "\0"))) {
            throw new InvalidShellTarget('ssh.private_key', 'Privater SSH-Schlüssel: höchstens 64 KiB.');
        }
        if (is_string($key) && trim($key) === '') {
            $key = null;
        }
        if ($key !== null) {
            $key = str_replace("\r\n", "\n", trim($key))."\n";
            if (preg_match('/^-----BEGIN (OPENSSH|RSA|EC|) ?PRIVATE KEY-----\n([A-Za-z0-9+\/=\n]+)\n-----END (?:OPENSSH|RSA|EC|) ?PRIVATE KEY-----\n$/D', $key, $keyParts) !== 1 || base64_decode(str_replace("\n", '', $keyParts[2]), true) === false) {
                throw new InvalidShellTarget('ssh.private_key', 'Einen privaten OpenSSH- oder PEM-Schlüssel ohne Passphrase einfügen.');
            }
            if ($keyParts[1] === 'OPENSSH') {
                $raw = base64_decode(str_replace("\n", '', $keyParts[2]), true);
                if (!str_starts_with($raw, "openssh-key-v1\0".pack('N', 4).'none')) {
                    throw new InvalidShellTarget('ssh.private_key', 'Für automatische SSH-Jobs einen eigenen Schlüssel ohne Passphrase verwenden.');
                }
            }
        }
        // Eine .pub-Zeile endet üblicherweise auf einen Kommentar (root@server); gespeichert wird nur Typ und Schlüssel.
        return new self($host, $port, $user, $parts[1] . ' ' . $parts[2], $key);
    }
    public function privateKey(): ?string
    {
        return $this->privateKey?->open();
    }
    /** @return array{host:string,port:int,username:string,host_key:string} */
    public function metadata(): array
    {
        return ['host' => $this->host,'port' => $this->port,'username' => $this->username,'host_key' => $this->hostKey];
    }
    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return $this->metadata() + ['private_key' => '[geschützt]'];
    }
}
