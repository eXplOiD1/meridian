<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Art eines Ausführungsorts (docs/decisions/0004 E1): `docker` = bestehender Container (Name), `host` = Profil des
 * Host-Agenten (Phase 4 S11).
 */
enum ShellTargetKind: string
{
    case Docker = 'docker';
    case Host = 'host';

    /** Allowlist-Muster für den Namen: Docker wie bei Docker selbst (auch Punkt), Host-Profil in Kleinbuchstaben. */
    /**
     * @return non-empty-string
     */
    public function namePattern(): string
    {
        return match ($this) {
            self::Docker => '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D',
            self::Host => '/^[a-z][a-z0-9-]{0,31}$/D',
        };
    }

    public function isValidName(string $name): bool
    {
        return preg_match($this->namePattern(), $name) === 1;
    }
}
