<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Database;

use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\TestCase;

/**
 * Migration 0005 baut runs neu auf: bestehende Läufe müssen vollständig erhalten bleiben (Roundtrip über
 * eine neue Verbindung), neue Spalten und Prüfungen greifen.
 */
final class SchedulerMigrationTest extends TestCase
{
    private string $file = '';
    private string $oldMigrations = '';

    protected function setUp(): void
    {
        $this->file = SchedulerHarness::tempDatabase();
        $this->oldMigrations = sys_get_temp_dir() . '/meridian-mig-' . bin2hex(random_bytes(4));
        mkdir($this->oldMigrations, 0o700);
        $all = glob(dirname(__DIR__, 3) . '/migrations/*.sql');
        foreach ($all === false ? [] : $all as $migration) {
            if (basename($migration) < '0005') {
                copy($migration, $this->oldMigrations . '/' . basename($migration));
            }
        }
    }

    protected function tearDown(): void
    {
        SchedulerHarness::removeDatabase($this->file);
        $files = glob($this->oldMigrations . '/*');
        foreach ($files === false ? [] : $files as $f) {
            unlink($f);
        }
        rmdir($this->oldMigrations);
    }

    public function testExistingRunsSurviveTheRebuildAndNewColumnsHaveDefaults(): void
    {
        $old = Connection::open($this->file);
        self::assertSame(['0001_init.sql', '0002_auth.sql', '0003_totp.sql', '0004_all_categories.sql'], (new Migrator($old, $this->oldMigrations))->migrate());
        $old->execute("INSERT INTO users (id, username, display_name, password_hash, created_at) VALUES (7, 'alex', 'Alex', 'x', '2026-01-01T00:00:00+00:00')");
        $old->execute(
            "INSERT INTO jobs (id, name, type, cron, payload_enc, overlap_policy, retry_count, next_run_at, created_at, updated_at)
             VALUES (3, 'Alt', 'shell', '0 3 * * *', 'v1:unecht', 'queue', 2, '2026-10-08T01:00:00+00:00', '2026-01-01T00:00:00+00:00', '2026-01-01T00:00:00+00:00')",
        );
        $old->execute(
            "INSERT INTO runs (id, job_id, trigger, status, started_at, finished_at, duration_ms, exit_code, http_status, output, started_by)
             VALUES (11, 3, 'manual', 'failed', '2026-10-01T03:00:00+00:00', '2026-10-01T03:00:02+00:00', 2000, 1, 503, 'alte Ausgabe', 7),
                    (12, 3, 'schedule', 'ok', '2026-10-02T03:00:00+00:00', NULL, NULL, NULL, NULL, NULL, NULL),
                    (13, 3, 'schedule', 'running', '2026-10-03T03:00:00+00:00', NULL, NULL, NULL, NULL, NULL, NULL)",
        );

        $db = Connection::open($this->file);
        self::assertSame(['0005_scheduler.sql','0006_run_heartbeat.sql','0007_http_jobs.sql','0008_display_host.sql','0009_users_roles.sql','0010_workers_and_live_log.sql','0011_shell_jobs.sql','0012_reveal_for_edit.sql','0013_custom_roles.sql','0014_job_expires_at.sql','0015_notifications.sql','0016_notification_kinds_public_url.sql','0017_run_retention.sql','0018_api_token_read_only.sql','0019_monitoring.sql','0020_monitors_status_pages.sql','0021_min_interval_setting.sql','0022_session_days_setting.sql','0023_user_language.sql','0024_user_theme.sql','0025_active_monitors.sql','0026_notification_language.sql','0027_runtime_warning.sql','0028_user_job_editor_mode.sql','0029_notification_recipients.sql','0030_monitor_channels.sql'], (new Migrator($db, dirname(__DIR__, 3) . '/migrations'))->migrate());

        $fresh = Connection::open($this->file);
        self::assertSame([
            ['id' => 11, 'job_id' => 3, 'trigger' => 'manual', 'status' => 'failed', 'scheduled_for' => null, 'attempt' => 1, 'started_at' => '2026-10-01T03:00:00+00:00', 'finished_at' => '2026-10-01T03:00:02+00:00', 'duration_ms' => 2000, 'exit_code' => 1, 'http_status' => 503, 'output' => 'alte Ausgabe', 'note' => null, 'worker' => null, 'started_by' => 7],
            ['id' => 12, 'job_id' => 3, 'trigger' => 'schedule', 'status' => 'ok', 'scheduled_for' => null, 'attempt' => 1, 'started_at' => '2026-10-02T03:00:00+00:00', 'finished_at' => null, 'duration_ms' => null, 'exit_code' => null, 'http_status' => null, 'output' => null, 'note' => null, 'worker' => null, 'started_by' => null],
        ], $fresh->fetchAll('SELECT id, job_id, trigger, status, scheduled_for, attempt, started_at, finished_at, duration_ms, exit_code, http_status, output, note, worker, started_by FROM runs WHERE id < 13 ORDER BY id'));
        self::assertSame(
            [['id' => 11, 'heartbeat_at' => null], ['id' => 12, 'heartbeat_at' => null], ['id' => 13, 'heartbeat_at' => '2026-10-03T03:00:00+00:00']],
            $fresh->fetchAll('SELECT id, heartbeat_at FROM runs ORDER BY id'),
            'laufende Altläufe übernehmen ihren Start als Herzschlag',
        );

        self::assertSame(
            ['overlap_policy' => 'queue', 'retry_count' => 2, 'catch_up' => 0, 'retry_delay_seconds' => 60, 'next_run_at' => '2026-10-08T01:00:00+00:00'],
            $fresh->fetchOne('SELECT overlap_policy, retry_count, catch_up, retry_delay_seconds, next_run_at FROM jobs WHERE id = 3'),
        );
        self::assertSame(['owner' => '', 'expires_at' => '1970-01-01T00:00:00+00:00'], $fresh->fetchOne('SELECT owner, expires_at FROM scheduler_lease'));
        self::assertSame([], $fresh->fetchAll('PRAGMA foreign_key_check'));
        $indexes = array_column($fresh->fetchAll("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'runs' ORDER BY name"), 'name');
        self::assertSame(['runs_exec_ref', 'runs_finished_at', 'runs_job_id', 'runs_job_started', 'runs_job_status', 'runs_runtime_pending', 'runs_status_scheduled'], $indexes);
    }

    public function testNewStatusIsAcceptedAndChecksStillHold(): void
    {
        $db = Connection::open($this->file);
        SchedulerHarness::migrate($db);
        $harness = new SchedulerHarness($db);
        $job = $harness->addJob();

        $harness->addRun($job, 'skipped');
        self::assertSame(['skipped'], $harness->statuses($job));

        foreach ([
            "INSERT INTO runs (job_id, trigger, status) VALUES (1, 'schedule', 'erfunden')",
            "INSERT INTO runs (job_id, trigger, status, attempt) VALUES (1, 'schedule', 'queued', 0)",
            "INSERT INTO runs (job_id, trigger, status) VALUES (1, 'cron', 'queued')",
            'UPDATE jobs SET catch_up = 2',
            'UPDATE jobs SET retry_delay_seconds = 0',
            'UPDATE jobs SET retry_delay_seconds = 3601',
            "INSERT INTO scheduler_lease (id, owner, expires_at) VALUES (2, 'zweiter', '1970-01-01T00:00:00+00:00')",
        ] as $sql) {
            try {
                $db->execute($sql);
                self::fail('Prüfung fehlt: ' . $sql);
            } catch (\PDOException) {
                // erwartet
            }
        }

        // Kaskade bleibt: Läufe eines gelöschten Jobs verschwinden mit ihm.
        $db->execute('DELETE FROM jobs WHERE id = :id', ['id' => $job]);
        self::assertSame([], $harness->runs($job));
    }
}
