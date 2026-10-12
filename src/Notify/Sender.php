<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Security\Sealed;

interface Sender
{
    /**
     * @param Sealed<string>|null $secret Passwort bzw. Token, falls gespeichert
     *
     * @throws SendFailed nur mit fester Meldung
     */
    public function send(ChannelRecord $channel, ?Sealed $secret, Message $message): void;
}
