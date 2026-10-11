<?php

declare(strict_types=1);

namespace Meridian\User;

/**
 * Eine gespeicherte Zuweisung für die Anzeige (ADR 0005, §4.4).
 *
 * `allCategories` gilt nur, wenn das Flag gesetzt ist **und** keine Kategoriezeile existiert (dieselbe Lesart wie
 * `UserRepository::grantsFor()`). `effective` ist false bei einer Zuweisung ohne Flag und ohne Kategorie (z. B. nach
 * dem Löschen der letzten Kategorie): sie gewährt nichts und wird nie zu „alle“ (E9, B12).
 */
final readonly class AssignmentView
{
    /**
     * @param array<int, string> $categories Kategorie-ID => Name
     */
    public function __construct(
        public int $roleId,
        public string $role,
        public bool $allCategories,
        public array $categories,
    ) {
    }

    public function effective(): bool
    {
        return $this->allCategories || $this->categories !== [];
    }
}
