<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

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
