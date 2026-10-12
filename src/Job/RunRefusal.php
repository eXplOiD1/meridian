<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Job;

/**
 * Warum ein manueller Lauf oder Testlauf nicht eingereiht wird (nach den Rechten und der Sichtbarkeit).
 */
enum RunRefusal
{
    /** Der Job ist deaktiviert; nur ein Testlauf ist dann erlaubt (→ 409). */
    case JobDisabled;

    /** Für den Job wartet oder läuft schon ein manueller Lauf oder Testlauf (→ 409). */
    case AlreadyOpen;

    /** Der Benutzer hat sein Stundenkontingent ausgeschöpft (→ 429). */
    case RateLimited;
}
