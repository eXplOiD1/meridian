<?php

declare(strict_types=1);

namespace Meridian\Backup;

/**
 * Sicherung oder Wiederherstellung ist gescheitert. Die Meldung ist fest formuliert und sagt, was zu tun ist; sie nennt
 * höchstens den Dateinamen der Sicherung, nie Inhalte, Schlüssel oder Systempfade.
 */
final class BackupFailed extends \RuntimeException
{
}
