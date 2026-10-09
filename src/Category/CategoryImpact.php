<?php

declare(strict_types=1);

namespace Meridian\Category;

/**
 * Was an einer Kategorie hängt (Folgen-Vorschau, ADR 0005 E9). Reine Zahlen und der Name.
 *
 * `assignments` zählt die Rollenzuweisungen, die diese Kategorie in ihrer Liste haben; `assignmentsIneffective`
 * davon die, für die sie die einzige wäre: sie bleiben nach dem Löschen bestehen, gewähren nichts und werden nie
 * zu „alle Kategorien“ (fail-closed).
 */
final readonly class CategoryImpact
{
    public function __construct(
        public int $id,
        public string $name,
        public int $jobs,
        public int $assignments,
        public int $assignmentsIneffective,
        public int $internalTargets,
    ) {
    }

    public function deletable(): bool
    {
        return $this->jobs === 0;
    }
}
