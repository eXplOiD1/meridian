<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Liefert die Freigaben interner Ziele, die für einen Job gelten können: globale und die seiner Kategorie.
 * Bei jedem Aufruf frisch (Änderungen gelten ohne Neustart).
 */
interface InternalTargetSource
{
    /**
     * @param int|null $categoryId gespeicherte Kategorie des Jobs aus der Datenbank; null = ohne Kategorie
     *
     * @return list<InternalTarget>
     */
    public function targetsFor(?int $categoryId): array;
}
