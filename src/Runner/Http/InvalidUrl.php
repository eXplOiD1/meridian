<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Die URL verletzt die Syntax aus UrlPolicy. Die Meldung sagt, was falsch ist, und enthält nie die URL.
 */
final class InvalidUrl extends \InvalidArgumentException
{
}
