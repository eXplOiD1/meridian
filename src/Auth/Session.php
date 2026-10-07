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
