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
    case Discord = 'discord';
    case Slack = 'slack';
    case Pushover = 'pushover';
    case HomeAssistant = 'homeassistant';
    case Browser = 'browser';

    /** Name des Geheimnisfelds in der Eingabe (Passwort bzw. Token). */
    public function secretField(): string
    {
        return match ($this) {
            self::Email => 'password',
            self::Ntfy, self::Webhook, self::Telegram, self::Discord, self::Slack, self::Pushover, self::HomeAssistant, self::Browser => 'token',
        };
    }
}
