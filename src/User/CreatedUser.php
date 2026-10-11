<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Security\OneTimePassword;

/**
 * Ergebnis von „Benutzer anlegen“. Das Einmalpasswort darf genau einmal in die Antwort an den Administrator
 * ({@see OneTimePassword::reveal()}), sonst nirgends hin.
 */
final readonly class CreatedUser
{
    public function __construct(
        public UserSummary $user,
        public OneTimePassword $password,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
