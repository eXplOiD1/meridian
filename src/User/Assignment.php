<?php

declare(strict_types=1);

namespace Meridian\User;

/**
 * Eine Zuweisung (Rolle + Geltungsbereich), wie sie gespeichert werden soll. Entsteht über
 * {@see AssignmentValidator}; der Konstruktor erzwingt trotzdem die Grundregel: „alle Kategorien“ genau dann, wenn
 * die Liste leer ist — eine leere Liste ohne Flag gibt es nicht (sie hieße nie „alle“).
 */
final readonly class Assignment
{
    /**
     * @param list<int> $categoryIds leer genau dann, wenn $allCategories
     */
    public function __construct(
        public int $roleId,
        public bool $allCategories,
        public array $categoryIds,
    ) {
        if ($allCategories !== ($categoryIds === [])) {
            throw new \InvalidArgumentException('Zuweisung: entweder „alle Kategorien“ oder mindestens eine Kategorie.');
        }
    }
}
