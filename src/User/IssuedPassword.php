<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Security\OneTimePassword;

/**
 * Ergebnis des Admin-Passwort-Resets (ADR 0005, E6/S5): neues Einmalpasswort und sein Ablauf. Der Klartext darf genau
 * einmal in die Antwort an den Administrator ({@see OneTimePassword::reveal()}), sonst nirgends hin.
 */
final readonly class IssuedPassword
{
    public function __construct(
        public OneTimePassword $password,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
