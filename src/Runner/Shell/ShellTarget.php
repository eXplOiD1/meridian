<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Ein Ausführungsort, wie ihn ein Job referenziert: Art und Name (kein Geheimnis).
 */
final readonly class ShellTarget
{
    public function __construct(public ShellTargetKind $kind, public string $name)
    {
    }

    /** `docker nextcloud` bzw. `host default` für Meldungen und Audit. */
    public function describe(): string
    {
        return $this->kind->value . ' ' . $this->name;
    }
}
