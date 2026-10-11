<?php

declare(strict_types=1);

namespace Meridian\Notify;

enum ChannelType: string
{
    case Email = 'email';
    case Ntfy = 'ntfy';

    /** Name des Geheimnisfelds in der Eingabe (Passwort bzw. Token). */
    public function secretField(): string
    {
        return match ($this) {
            self::Email => 'password',
            self::Ntfy => 'token',
        };
    }
}
