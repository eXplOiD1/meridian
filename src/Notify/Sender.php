<?php

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
