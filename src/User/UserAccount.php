<?php

declare(strict_types=1);

namespace Meridian\User;

final readonly class UserAccount
{
    public function __construct(
        public int $id,
        public string $username,
        public string $displayName,
        #[\SensitiveParameter]
        public string $passwordHash,
        public bool $isActive,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['id' => (string) $this->id, 'username' => $this->username, 'passwordHash' => '••••'];
    }
}
