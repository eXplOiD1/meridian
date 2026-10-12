<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

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
