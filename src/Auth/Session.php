<?php

declare(strict_types=1);

namespace Meridian\Auth;

/**
 * Eine gültige Sitzung. Der Token ist der Klartext aus dem Cookie, nie aus der Datenbank.
 */
final readonly class Session
{
    public function __construct(
        public int $userId,
        #[\SensitiveParameter]
        public string $token,
        /** Zeilen-ID in `sessions` (für „diese Sitzung“ in der Übersicht), null nur in Sonderfällen. */
        public ?int $id = null,
        /** Einmalpasswort noch nicht ersetzt: nur `me`, `password`, `logout` (ADR 0005, E6). */
        public bool $passwordChangeRequired = false,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['userId' => (string) $this->userId, 'token' => '••••'];
    }
}
