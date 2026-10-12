<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Settings;

/**
 * Wert einer Einstellung ungültig. Die Meldung sagt, was erlaubt ist, und nennt nie den eingegebenen Wert.
 */
final class InvalidSetting extends \InvalidArgumentException
{
}
