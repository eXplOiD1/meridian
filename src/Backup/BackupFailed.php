<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Backup;

/**
 * Sicherung oder Wiederherstellung ist gescheitert. Die Meldung ist fest formuliert und sagt, was zu tun ist; sie nennt
 * höchstens den Dateinamen der Sicherung, nie Inhalte, Schlüssel oder Systempfade.
 */
final class BackupFailed extends \RuntimeException
{
}
