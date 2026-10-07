<?php

declare(strict_types=1);

namespace Meridian\Auth;

/**
 * Zu viele Fehlversuche: der Versuch wurde gar nicht erst geprüft.
 */
final class TooManyAttempts extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Zu viele Fehlversuche. Bitte später erneut versuchen.');
    }
}
