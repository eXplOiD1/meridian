<?php

declare(strict_types=1);

namespace Meridian\Database;

/**
 * Spielt die SQL-Dateien aus migrations/ in Namensreihenfolge ein, jede genau einmal.
 */
final class Migrator
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $directory,
    ) {
    }

    /**
     * @phpstan-impure
     *
     * @return list<string> Namen der neu eingespielten Migrationen
     */
    public function migrate(): array
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)',
        );

        $applied = array_map(
            static fn (array $row): string => is_string($row['name'] ?? null) ? $row['name'] : '',
            $this->db->fetchAll('SELECT name FROM schema_migrations'),
        );

        $files = glob($this->directory . '/*.sql');
        if ($files === false) {
            throw new \RuntimeException('Migrationsverzeichnis nicht lesbar.');
        }
        sort($files, SORT_STRING);

        $new = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException('Migration ' . $name . ' nicht lesbar.');
            }

            $this->db->transaction(function (Connection $db) use ($sql, $name): void {
                $db->executeMigrationScript($sql);
                $db->execute(
                    'INSERT INTO schema_migrations (name, applied_at) VALUES (:name, :at)',
                    ['name' => $name, 'at' => gmdate('c')],
                );
            });
            $new[] = $name;
        }

        return $new;
    }
}
