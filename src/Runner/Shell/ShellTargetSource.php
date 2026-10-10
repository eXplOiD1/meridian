<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Quelle der Freigaben (Allowlist). Der Runner liest nur; geschrieben wird über die Admin-API und die Befehlszeile.
 */
interface ShellTargetSource
{
    /**
     * Freigaben mit gleicher Art und gleichem Namen, die global gelten oder genau für diese Kategorie. Ohne Kategorie
     * (`null`) gelten nur globale Freigaben, nie die einer Kategorie.
     *
     * @return list<ShellTargetGrant>
     */
    public function grantsFor(ShellTarget $target, ?int $categoryId): array;
}
