<?php

declare(strict_types=1);

namespace Meridian\Database;

/**
 * Dünne Hülle um PDO/SQLite.
 *
 * Regel: SQL ist immer ein Literal im Code, Werte gehen ausschließlich über Parameter.
 * Es gibt bewusst keine Methode, die zusammengesetzte SQL-Strings mit Werten annimmt.
 */
final class Connection
{
    private function __construct(private readonly \PDO $pdo)
    {
    }

    public static function open(string $path): self
    {
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return new self($pdo);
    }

    public static function inMemory(): self
    {
        return self::open(':memory:');
    }

    /**
     * @param literal-string                         $sql
     * @param array<string, string|int|float|bool|null> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /**
     * @param literal-string                         $sql
     * @param array<string, string|int|float|bool|null> $params
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll();
    }

    /**
     * @param literal-string                         $sql
     * @param array<string, string|int|float|bool|null> $params
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($sql, $params);

        return $rows[0] ?? null;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Nur für Migrationsdateien aus dem Repository, nie für Benutzereingaben.
     *
     * @internal
     */
    public function executeMigrationScript(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /**
     * @template T
     *
     * @param callable(self): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
