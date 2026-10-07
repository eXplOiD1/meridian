<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Der Hostname liefert keine (gültige) Adresse. Feste Meldung ohne Hostnamen; wiederholbar.
 */
final class TargetUnresolvable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ziel nicht auflösbar: Hostname prüfen.');
    }
}
