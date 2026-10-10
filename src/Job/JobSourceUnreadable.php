<?php

declare(strict_types=1);

namespace Meridian\Job;

/**
 * Gespeicherte Anfrage bzw. Skript lässt sich nicht entschlüsseln oder lesen. Feste Meldung, nie Inhalt.
 */
final class JobSourceUnreadable extends \RuntimeException
{
    public const MESSAGE = 'Die gespeicherten Werte dieses Jobs lassen sich nicht lesen. Den Hauptschlüssel (/etc/meridian) prüfen oder Anfrage bzw. Skript im Editor neu eingeben.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
