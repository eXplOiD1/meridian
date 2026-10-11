<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Database\Connection;
use Meridian\Security\CategoryScope;

/**
 * Kategorien lesen. Anlegen, Umbenennen und Löschen läuft über {@see \Meridian\Category\CategoryService}.
 */
final class CategoryRepository
{
    /** Das Prädikat ist derselbe Text wie {@see ScopeSql::PREDICATE} (ein Test prüft das). */
    private const LIST_SQL = 'SELECT c.id, c.name FROM categories c
      WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))
      ORDER BY c.name COLLATE NOCASE, c.id';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Die Kategorien im Bereich. Der Bereich ist das Ergebnis von `AccessControl::scope()`, nie eine Anfrage.
     *
     * @return list<array{id: int, name: string}>
     */
    public function visible(CategoryScope $scope): array
    {
        $list = [];
        foreach ($this->db->fetchAll(self::LIST_SQL, $scope->sqlParameters()) as $row) {
            $list[] = ['id' => Row::int($row, 'id'), 'name' => Row::string($row, 'name')];
        }

        return $list;
    }

    /** Name einer bestehenden Kategorie, sonst null. */
    public function nameOf(int $id): ?string
    {
        $row = $this->db->fetchOne('SELECT name FROM categories WHERE id = :id', ['id' => $id]);

        return $row === null ? null : Row::string($row, 'name');
    }
}
