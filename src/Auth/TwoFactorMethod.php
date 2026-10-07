<?php

declare(strict_types=1);

namespace Meridian\Auth;

enum TwoFactorMethod: string
{
    case Totp = 'totp';
    case Recovery = 'recovery';
}
