<?php

declare(strict_types=1);

namespace Meridian\Tests\Support;

use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Database\Timestamp;
use Meridian\Runner\RunnerRegistry;
use Meridian\Schedule\DbRunAuthorizer;
use Meridian\Schedule\Planner;
use Meridian\Schedule\Scheduler;
use Meridian\Schedule\SchedulerLease;
use Meridian\Schedule\Worker;
use Meridian\Security\PasswordHasher;
use Meridian\User\UserRepository;

/**
 * Baut Datenbank, Uhr, Sperre, Planer und Worker für Scheduler-Tests zusammen.
 */
final class SchedulerHarness
{
    public const OWNER = 'test-scheduler';

    /** Offensichtlich unechter Payload-Wert: darf in keiner Ausgabe auftauchen. */
    public const FAKE_PAYLOAD = 'v1:FAKE-PAYLOAD-nicht-echt';

    public readonly Connection $db;
    public readonly MutableClock $clock;
    public readonly SchedulerLease $lease;
    public readonly RunnerRegistry $runners;
    public readonly Planner $planner;
    public readonly Worker $worker;
    public readonly Scheduler $scheduler;
    public readonly \Meridian\Schedule\StaleRuns $staleRuns;

    public function __construct(?Connection $db = null, string $start = '2026-10-07 08:00:00', string $owner = self::OWNER)
    {
        if ($db === null) {
            $db = Connection::inMemory();
            self::migrate($db);
        }
        $this->db = $db;
        $this->clock = new MutableClock($start);
        $this->lease = new SchedulerLease($db, $this->clock, $owner);
        $this->runners = new RunnerRegistry();
        $this->planner = new Planner($db, $this->clock, $this->lease);
        $this->worker = new Worker($db, $this->clock, new \Meridian\Schedule\WorkerIdentity($owner, \Meridian\Runner\JobType::Http), [\Meridian\Runner\JobType::Http, \Meridian\Runner\JobType::Shell], $this->runners, new DbRunAuthorizer($db, new UserRepository($db, new PasswordHasher())), fn (\Meridian\Runner\Heartbeat $inner): \Meridian\Runner\Heartbeat => new \Meridian\Schedule\LeaseHeartbeat($inner, $this->lease, $this->clock));
        $this->staleRuns = new \Meridian\Schedule\StaleRuns($db, $this->clock, $this->lease);
        $this->scheduler = new Scheduler($this->lease, $this->planner, $this->staleRuns, $this->worker);
    }

    public static function migrate(Connection $db): void
    {
        (new Migrator($db, dirname(__DIR__, 2) . '/migrations'))->migrate();
    }

    /**
     * @param array{cron?: string, timezone?: string, overlap?: string, retry_count?: int, retry_delay?: int, catch_up?: int, enabled?: int, next_run_at?: ?string, type?: string, category_id?: ?int, config_json?: string, payload_enc?: string} $o
     */
    public function addJob(array $o = []): int
    {
        $this->db->execute(
            'INSERT INTO jobs (name, type, category_id, cron, timezone, config_json, payload_enc, overlap_policy, retry_count, retry_delay_seconds, catch_up, is_enabled, next_run_at, created_at, updated_at)
             VALUES (:name, :type, :category, :cron, :tz, :config, :payload, :overlap, :retry, :delay, :catch, :enabled, :next, :c, :c)',
            [
                'name' => 'Testjob',
                'type' => $o['type'] ?? 'http',
                'cron' => $o['cron'] ?? '*/10 * * * *',
                'tz' => $o['timezone'] ?? 'Europe/Berlin',
                'category' => $o['category_id'] ?? null,
                'config' => $o['config_json'] ?? '{}',
                'payload' => $o['payload_enc'] ?? self::FAKE_PAYLOAD,
                'overlap' => $o['overlap'] ?? 'skip',
                'retry' => $o['retry_count'] ?? 0,
                'delay' => $o['retry_delay'] ?? 60,
                'catch' => $o['catch_up'] ?? 0,
                'enabled' => $o['enabled'] ?? 1,
                'next' => array_key_exists('next_run_at', $o) ? $o['next_run_at'] : '2026-10-07T08:00:00+00:00',
                'c' => '2026-10-01T00:00:00+00:00',
            ],
        );

        return $this->db->lastInsertId();
    }

    public function addRun(int $jobId, string $status, ?string $worker = null, ?string $scheduledFor = null, string $trigger = 'schedule', ?int $startedBy = null): int
    {
        $this->db->execute(
            'INSERT INTO runs (job_id, trigger, status, scheduled_for, worker, started_by) VALUES (:j, :t, :s, :f, :w, :b)',
            ['j' => $jobId, 't' => $trigger, 's' => $status, 'f' => $scheduledFor, 'w' => $worker, 'b' => $startedBy],
        );

        return $this->db->lastInsertId();
    }

    /**
     * Benutzer mit einer Rolle, optional auf Kategorien (Namen, müssen existieren) beschränkt. Ohne Passwort-Hash
     * (Anmeldung spielt hier keine Rolle).
     *
     * @param list<string>|null $categories null = alle Kategorien
     */
    public function addUser(string $username, string $role, ?array $categories = null): int
    {
        $this->db->execute(
            "INSERT INTO users (username, display_name, password_hash, created_at) VALUES (:u, :u, 'kein-hash', :c)",
            ['u' => $username, 'c' => '2026-10-01T00:00:00+00:00'],
        );
        $userId = $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO user_roles (user_id, role_id, all_categories) SELECT :u, id, :all FROM roles WHERE name = :r',
            ['u' => $userId, 'r' => $role, 'all' => $categories === null ? 1 : 0],
        );
        $grantId = $this->db->lastInsertId();
        foreach ($categories ?? [] as $category) {
            $this->db->execute(
                'INSERT INTO user_role_categories (user_role_id, category_id) SELECT :g, id FROM categories WHERE name = :n',
                ['g' => $grantId, 'n' => $category],
            );
        }

        return $userId;
    }

    public function addCategory(string $name): int
    {
        $this->db->execute('INSERT INTO categories (name) VALUES (:n)', ['n' => $name]);

        return $this->db->lastInsertId();
    }

    public function nextRunAt(int $jobId): ?string
    {
        $row = $this->db->fetchOne('SELECT next_run_at FROM jobs WHERE id = :id', ['id' => $jobId]);
        $value = $row['next_run_at'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<array{id: int, status: string, trigger: string, scheduled_for: ?string, attempt: int, note: ?string, output: ?string, worker: ?string}>
     */
    public function runs(int $jobId): array
    {
        $rows = [];
        foreach ($this->db->fetchAll('SELECT id, status, trigger, scheduled_for, attempt, note, output, worker FROM runs WHERE job_id = :j ORDER BY id', ['j' => $jobId]) as $r) {
            $rows[] = [
                'id' => is_int($r['id']) ? $r['id'] : 0,
                'status' => is_string($r['status']) ? $r['status'] : '',
                'trigger' => is_string($r['trigger']) ? $r['trigger'] : '',
                'scheduled_for' => is_string($r['scheduled_for']) ? $r['scheduled_for'] : null,
                'attempt' => is_int($r['attempt']) ? $r['attempt'] : 0,
                'note' => is_string($r['note']) ? $r['note'] : null,
                'output' => is_string($r['output']) ? $r['output'] : null,
                'worker' => is_string($r['worker']) ? $r['worker'] : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function statuses(int $jobId): array
    {
        return array_map(static fn (array $r): string => $r['status'], $this->runs($jobId));
    }

    /**
     * Uhr vorstellen und die Sperre verlängern, wie es der laufende Prozess in jedem Takt tut.
     */
    public function advance(int $seconds): void
    {
        $this->clock->advance($seconds);
        $this->lease->acquire();
    }

    public function now(): string
    {
        return Timestamp::format($this->clock->now());
    }

    /**
     * @return \Closure(): bool
     */
    public static function never(): \Closure
    {
        return static fn (): bool => false;
    }

    public static function tempDatabase(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'meridian-sched-');
        if ($file === false) {
            throw new \RuntimeException('Temporäre Datei nicht anlegbar.');
        }

        return $file;
    }

    public static function removeDatabase(string $file): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($file . $suffix)) {
                unlink($file . $suffix);
            }
        }
    }
}
