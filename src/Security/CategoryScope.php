<?php

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Sichtbarer Bereich für ein Recht: entweder ausdrücklich „alle Kategorien“ oder eine Liste von
 * Kategorienamen. Eine leere Liste heißt „nichts“, nie „alles“.
 *
 * Entsteht nur über AccessControl::scope(), damit Liste, Detail, Verlauf und Lauf dieselbe Regel benutzen
 * wie AccessControl::can(). Datensätze ohne Kategorie liegen nur im Bereich „alle“.
 */
final readonly class CategoryScope
{
    /**
     * @param list<string> $names sortiert, ohne Doppelte; bedeutungslos bei $all = true
     */
    private function __construct(
        private bool $all,
        private array $names,
    ) {
    }

    public static function everything(): self
    {
        return new self(true, []);
    }

    public static function nothing(): self
    {
        return new self(false, []);
    }

    /**
     * @param list<string> $names
     */
    public static function only(array $names): self
    {
        $unique = array_values(array_unique($names));
        sort($unique, SORT_STRING);

        return new self(false, $unique);
    }

    public function isAll(): bool
    {
        return $this->all;
    }

    public function isEmpty(): bool
    {
        return !$this->all && $this->names === [];
    }

    /**
     * @return list<string> Kategorienamen; leer bei „alle“ (dann isAll() fragen)
     */
    public function names(): array
    {
        return $this->all ? [] : $this->names;
    }

    /**
     * Liegt ein Datensatz mit dieser (gespeicherten) Kategorie im Bereich? null = ohne Kategorie.
     */
    public function contains(?string $category): bool
    {
        if ($this->all) {
            return true;
        }

        return $category !== null && in_array($category, $this->names, true);
    }

    /**
     * Parameter für das gemeinsame SQL-Prädikat der Repositories:
     * `WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))`.
     *
     * @return array{scope_all: int, scope_names: string}
     */
    public function sqlParameters(): array
    {
        return [
            'scope_all' => $this->all ? 1 : 0,
            'scope_names' => json_encode($this->all ? [] : $this->names, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }
}
