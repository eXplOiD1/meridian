<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

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
