<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Ziel gesperrt (§5.2/§5.3). Feste Meldung ohne Adresse und ohne URL; nicht wiederholbar.
 * Bei freigebbaren Klassen nennt die Meldung, dass ein Admin freigeben kann (R3, Entscheidung Alex).
 */
final class TargetBlocked extends \RuntimeException
{
    public function __construct(public readonly BlockReason $reason)
    {
        parent::__construct($reason->isReleasable()
            ? 'Ziel gesperrt: Die Adresse liegt in einem internen oder reservierten Netz. Ein Admin kann interne Ziele freigeben (global oder für die Kategorie des Jobs).'
            : 'Ziel gesperrt: Die Adresse liegt in einem Netz, das nie freigegeben werden kann (z. B. Link-local, Metadaten-Dienst, Multicast, reserviert oder Meridians eigene Infrastruktur: Docker-Proxy, Docker-API-Ports 2375/2376, eigener Port).');
    }
}
