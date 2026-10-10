<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Security\RoleGrant;

/**
 * Handelnder bei einer Verwaltungsaktion (ADR 0005, §4.1): angemeldeter Benutzer, **frisch geladene** Rechte und die
 * Client-IP für die Sperre bei der Passwort-Bestätigung.
 */
final readonly class Actor
{
    /**
     * @param list<RoleGrant> $grants
     */
    public function __construct(
        public UserAccount $user,
        public array $grants,
        public string $ip,
    ) {
    }
}
