<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Die Verbindungsdaten eines SSH-Ausführungsorts, wie der Executor sie braucht: validiert, mit festgehaltenem
 * Server-Schlüssel und dem **verschlüsselten** privaten Schlüssel. Der Klartext entsteht erst im Executor und nur in
 * dessen privatem Arbeitsverzeichnis. Keine Darstellung zeigt den Schlüssel; serialize() wirft.
 */
final readonly class SshConnection
{
    private const KEY_TYPES = '/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+\/=]+$/D';

    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public string $hostKey,
        #[\SensitiveParameter] public string $privateKeyEnc,
    ) {
        if ($host === '' || $port < 1 || $port > 65535 || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,31}$/D', $username) !== 1
            || preg_match(self::KEY_TYPES, $hostKey) !== 1 || $privateKeyEnc === '') {
            throw new \InvalidArgumentException('Ungültige SSH-Verbindungsdaten.');
        }
    }

    /** Schlüsseltyp des festgehaltenen Server-Schlüssels, zum Beispiel `ssh-ed25519`. */
    public function hostKeyType(): string
    {
        return explode(' ', $this->hostKey, 2)[0];
    }

    /**
     * Eine Zeile für `known_hosts`: Port 22 ohne Klammern, sonst `[host]:port`. Nur dieser eine Schlüssel gilt.
     */
    public function knownHostsLine(): string
    {
        $name = $this->port === 22 ? $this->host : '[' . $this->host . ']:' . $this->port;

        return $name . ' ' . $this->hostKey . "\n";
    }

    /**
     * @return array<string, int|string>
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'username' => $this->username, 'private_key' => '[geschützt]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Eine SSH-Verbindung wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Eine SSH-Verbindung wird nicht deserialisiert.');
    }
}
