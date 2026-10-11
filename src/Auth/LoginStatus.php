<?php

declare(strict_types=1);

namespace Meridian\Auth;

enum LoginStatus
{
    case Success;
    case Invalid;
    case Locked;
    /** Passwort stimmt, aber der Benutzer hat 2FA aktiviert und keinen Code mitgeschickt. */
    case TotpRequired;
}
