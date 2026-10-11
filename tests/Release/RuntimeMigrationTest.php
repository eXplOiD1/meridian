<?php

declare(strict_types=1);

namespace Meridian\Tests\Release;

use Meridian\Database\Connection;
use PHPUnit\Framework\TestCase;

final class RuntimeMigrationTest extends TestCase
{
    private function oldDatabase(): Connection
    {
        // This test only ever modifies an in-memory database, never installation data.
        $db = Connection::inMemory();
        $files = glob(dirname(__DIR__, 2) . '/migrations/*.sql');
        sort($files);
        foreach ($files as $file) {
            if (basename($file) === '0027_runtime_warning.sql') {
                break;
            }
            $db->transaction(static fn (Connection $db) => $db->executeMigrationScript(file_get_contents($file)));
        }
        $db->execute("INSERT INTO notification_channels (id, name, type, config_json, created_at, updated_at) VALUES (1, 'Test', 'ntfy', '{}', '2026-10-11', '2026-10-11')");
        foreach (['pending', 'sent', 'failed'] as $status) {
            $db->execute("INSERT INTO notification_outbox (kind, channel_id, job_name, occurred_at, detail, failures, status, attempts, next_attempt_at, last_error, created_at, sent_at) VALUES ('failure', 1, 'Example', '2026-10-11', 'Preserve this detail', 3, :status, 2, '2026-10-12', 'Preserve error', '2026-10-11', '2026-10-11')", ['status' => $status]);
        }
        return $db;
    }

    public function testAllExistingOutboxRowsArePreserved(): void
    {
        $db = $this->oldDatabase();
        $before = $db->fetchAll('SELECT * FROM notification_outbox ORDER BY id');
        $sql = file_get_contents(dirname(__DIR__, 2) . '/migrations/0027_runtime_warning.sql');
        $db->transaction(static fn (Connection $db) => $db->executeMigrationScript($sql));
        self::assertSame($before, $db->fetchAll('SELECT * FROM notification_outbox ORDER BY id'));
        $db->execute("INSERT INTO notification_outbox (kind, channel_id, job_name, occurred_at, next_attempt_at, created_at) VALUES ('slow', 1, 'Example', '2026-10-11', '2026-10-11', '2026-10-11')");
        self::assertCount(4, $db->fetchAll('SELECT id FROM notification_outbox'));
    }

    public function testFailureRollsBackSchemaAndMessagesTogether(): void
    {
        $db = $this->oldDatabase();
        $before = $db->fetchAll('SELECT * FROM notification_outbox ORDER BY id');
        $sql = file_get_contents(dirname(__DIR__, 2) . '/migrations/0027_runtime_warning.sql');
        try {
            $db->transaction(static fn (Connection $db) => $db->executeMigrationScript($sql . "
INSERT INTO deliberately_missing_table VALUES (1);"));
            self::fail('Expected injected migration failure');
        } catch (\PDOException) {
            self::assertSame($before, $db->fetchAll('SELECT * FROM notification_outbox ORDER BY id'));
            self::assertSame([], $db->fetchAll("SELECT name FROM pragma_table_info('jobs') WHERE name = 'runtime_warn_percent'"));
            self::assertSame([], $db->fetchAll("SELECT name FROM sqlite_master WHERE name = 'notification_outbox_runtime'"));
        }
    }
}
