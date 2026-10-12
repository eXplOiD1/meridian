<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Notify;

enum ChannelType: string
{
    case Email = 'email';
    case Ntfy = 'ntfy';
    case Webhook = 'webhook';
    case Telegram = 'telegram';

    /** Name des Geheimnisfelds in der Eingabe (Passwort bzw. Token). */
    public function secretField(): string
    {
        return match ($this) {
            self::Email => 'password',
            self::Ntfy, self::Webhook, self::Telegram => 'token',
        };
    }
}
