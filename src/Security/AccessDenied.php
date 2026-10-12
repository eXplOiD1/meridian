<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Security;

final class AccessDenied extends \RuntimeException
{
    public function __construct(public readonly Permission $permission)
    {
        parent::__construct('Dafür fehlt das Recht „' . $permission->value . '“.');
    }
}
