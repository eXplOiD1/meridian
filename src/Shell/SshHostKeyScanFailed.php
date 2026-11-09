<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);

namespace Meridian\Shell;

/** Der Host-Schlüssel eines SSH-Servers ließ sich nicht abrufen; die Meldung ist ein fester Text ohne Serverausgabe. */
final class SshHostKeyScanFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}
