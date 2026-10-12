<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Fehler beim Umgang mit Geheimnissen. Die Meldung enthält nie den geheimen Wert selbst.
 */
final class SecretException extends \RuntimeException
{
}
