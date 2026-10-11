<?php

declare(strict_types=1);

namespace Meridian\User;

/**
 * Lesemodell für Liste und Detail der Benutzerverwaltung. Enthält nie `password_hash`, `totp_secret_enc`,
 * Wiederherstellungscodes oder `token_hash` — diese Spalten werden gar nicht erst gelesen.
 */
final readonly class UserSummary
{
    /**
     * @param list<AssignmentView> $assignments
     */
    public function __construct(
        public int $id,
        public string $username,
        public string $displayName,
        public UserStatus $status,
        public bool $totpEnabled,
        public bool $passwordChangeRequired,
        public ?string $lastLoginAt,
        public string $createdAt,
        public array $assignments,
    ) {
    }
}
