<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Database\Timestamp;

final readonly class UserAccount
{
    public function __construct(
        public int $id,
        public string $username,
        public string $displayName,
        #[\SensitiveParameter]
        public string $passwordHash,
        public bool $isActive,
        /** Einmalpasswort noch nicht ersetzt: ohne Rechte, nur `me`/`password`/`logout` (ADR 0005, E6). */
        public bool $passwordMustChange = false,
        /** Ablauf des Einmalpassworts (UTC, Datenbankformat), sonst null. */
        public ?string $passwordExpiresAt = null,
        /** Als gelöscht markiert (Soft-Delete, E5), sonst null. Gelöscht heißt immer inaktiv. */
        public ?string $deletedAt = null,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * Ein Einmalpasswort gilt nur bis zu seinem Ablauf. Ohne Pflichtwechsel läuft ein Passwort nie ab.
     */
    public function passwordExpired(\DateTimeImmutable $now): bool
    {
        if (!$this->passwordMustChange || $this->passwordExpiresAt === null) {
            return false;
        }

        try {
            return $now >= Timestamp::parse($this->passwordExpiresAt);
        } catch (\InvalidArgumentException) {
            // Unlesbarer Ablauf: im Zweifel abgelaufen (fail-closed).
            return true;
        }
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['id' => (string) $this->id, 'username' => $this->username, 'passwordHash' => '••••'];
    }
}
