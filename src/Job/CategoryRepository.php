<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Database\Connection;
use Meridian\Security\CategoryScope;

/**
 * Kategorien lesen und anlegen (Löschen folgt mit der Kategorie-Verwaltung; bis dahin legt sie die CLI `category:create` an).
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

    /**
     * Legt eine Kategorie an. Der Name muss vorher über {@see CategoryName::isValid()} geprüft sein; Namen, die sich
     * nur in Groß- und Kleinschreibung unterscheiden, gelten als doppelt (sonst sähen zwei Kategorien gleich aus).
     * Legt nichts an und gibt null zurück, wenn der Name schon vergeben ist.
     *
     * Eine neue Kategorie gehört niemandem: sie erweitert keine bestehende Beschränkung auf Kategorielisten
     * (`user_role_categories` bleibt unberührt), nur Rollen mit „alle Kategorien“ sehen sie.
     */
    public function create(string $name): ?int
    {
        if (!CategoryName::isValid($name)) {
            throw new \InvalidArgumentException('Ungültiger Kategoriename.');
        }

        return $this->db->immediate(function () use ($name): ?int {
            if ($this->db->fetchOne('SELECT 1 AS taken FROM categories WHERE name = :name COLLATE NOCASE', ['name' => $name]) !== null) {
                return null;
            }
            $this->db->execute('INSERT INTO categories (name) VALUES (:name)', ['name' => $name]);

            return $this->db->lastInsertId();
        });
    }

    /** Name einer bestehenden Kategorie, sonst null. */
    public function nameOf(int $id): ?string
    {
        $row = $this->db->fetchOne('SELECT name FROM categories WHERE id = :id', ['id' => $id]);

        return $row === null ? null : Row::string($row, 'name');
    }
}
