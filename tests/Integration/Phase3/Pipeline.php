<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Phase3;

use Meridian\Auth\SystemClock;
use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Runner\Http\AddressPolicy;
use Meridian\Runner\Http\CurlTransport;
use Meridian\Runner\Http\DbHttpJobSource;
use Meridian\Runner\Http\DbInternalTargetSource;
use Meridian\Runner\Http\HostResolver;
use Meridian\Runner\Http\HttpRunner;
use Meridian\Runner\Http\StoredHttpRunSettings;
use Meridian\Runner\Http\TargetGuard;
use Meridian\Runner\JobType;
use Meridian\Runner\RunnerRegistry;
use Meridian\Schedule\DbRunAuthorizer;
use Meridian\Schedule\Planner;
use Meridian\Schedule\RunEvent;
use Meridian\Schedule\Scheduler;
use Meridian\Schedule\SchedulerLease;
use Meridian\Schedule\Worker;
use Meridian\Security\KeyLoader;
use Meridian\Security\PasswordHasher;
use Meridian\Security\SecretBox;
use Meridian\Settings\Settings;
use Meridian\Tests\Support\JobApiHarness;
use Meridian\Tests\Support\MutableClock;
use Meridian\User\UserRepository;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gemeinsames Gerüst der Gesamtsuite: Dateidatenbank und Schlüsseldatei in einem Datenordner (die echte
 * `bin/meridian` arbeitet auf denselben Dateien), Kernel mit den echten Controllern, Scheduler wahlweise als
 * echter Prozess (`scheduler:run --once -vvv`) oder im Prozess mit echtem HttpRunner und frei wählbarem Resolver.
 *
 * Alles, was ein Mensch oder ein Angreifer je zu sehen bekommt, wird in {@see self::$captured} gesammelt und am
 * Ende nach den Test-Geheimnissen durchsucht ({@see self::assertNoLeak()}).
 */
trait Pipeline
{
    use JobApiHarness;

    private string $dir = '';
    private string $keyFile = '';
    private string $errorLog = '';
    private string $previousErrorLog = '';
    private ?Connection $fileDb = null;
    private ?SecretBox $fileBox = null;
    private ?MutableClock $realClock = null;
    private int $apiCalls = 0;

    /** @var array<string, string> Name der Ausgabestelle → gesammelter Text */
    private array $captured = [];

    /** @var list<LeakServer> */
    private array $servers = [];

    private function pipelineUp(): void
    {
        $dir = sys_get_temp_dir() . '/meridian-leak-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);
        $this->dir = $dir;
        $this->keyFile = $dir . '/key';
        // Zufälliger Schlüssel je Test, nie ein Literal im Quelltext.
        file_put_contents($this->keyFile, KeyLoader::generate() . "\n");
        chmod($this->keyFile, 0o600);
        $this->errorLog = $dir . '/php-error.log';
        file_put_contents($this->errorLog, '');
        $previous = ini_set('error_log', $this->errorLog);
        $this->previousErrorLog = $previous === false ? '' : $previous;

        $this->fileBox = new SecretBox(KeyLoader::fromFile($this->keyFile));
        $db = $this->db();
        (new Migrator($db, dirname(__DIR__, 3) . '/migrations'))->migrate();
        $db->execute("INSERT INTO categories (id, name) VALUES (1, 'A'), (2, 'B')");
    }

    private function pipelineDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
        ini_set('error_log', $this->previousErrorLog);
        $this->fileDb = null;
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
    }

    private function db(): Connection
    {
        return $this->fileDb ??= Connection::open($this->dir . '/meridian.sqlite');
    }

    private function box(): SecretBox
    {
        Assert::assertNotNull($this->fileBox);

        return $this->fileBox;
    }

    /** Eingefrorene Uhr, aber auf der echten Zeit: der Scheduler-Prozess rechnet mit der Systemuhr. */
    private function clock(): MutableClock
    {
        return $this->realClock ??= new MutableClock(gmdate('Y-m-d H:i:s'));
    }

    private function server(string $serverSecret = 'none'): LeakServer
    {
        return $this->servers[] = new LeakServer($serverSecret);
    }

    // ------------------------------------------------------------------------------------------------ Anfragen

    /**
     * Wie api(), aber jede Antwort (Status, Header, Inhalt) wandert in die Sammlung „api“.
     *
     * @param array<string, mixed> $body
     */
    private function req(?string $who, string $method, string $path, array $body = []): Response
    {
        $response = $this->api($who, $method, $path, $body);
        // Nie den Pfad der Anfrage in die Sammlung schreiben: er kann das Geheimnis selbst enthalten.
        $this->capture('api', $method . ' #' . ++$this->apiCalls . ' → ' . $response->getStatusCode() . "\n" . $response->headers . "\n" . $response->getContent());

        return $response;
    }

    private function capture(string $surface, string $text): void
    {
        $this->captured[$surface] = ($this->captured[$surface] ?? '') . $text . "\n";
    }

    /**
     * Legt einen Job über die API an und gibt seine Nummer zurück.
     *
     * @param array<string, mixed> $body
     */
    private function createJob(array $body, string $who = 'admin', int $expect = 201): int
    {
        $response = $this->req($who, 'POST', '/api/jobs', $body);
        Assert::assertSame($expect, $response->getStatusCode(), $this->plain($response));
        $job = self::dig($this->json($response), 'job', 'id');

        return is_int($job) ? $job : 0;
    }

    /**
     * @param array<string, mixed> $http
     * @param array<string, mixed> $request
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function jobBody(array $request, array $http = [], array $extra = []): array
    {
        return $extra + [
            'name' => 'Leak-Job',
            'type' => 'http',
            'category_id' => 1,
            // Nie fällig: Läufe entstehen nur durch die Tests.
            'cron' => '0 3 1 1 *',
            'timezone' => 'Europe/Berlin',
            'http' => $http + ['method' => 'GET', 'timeout_seconds' => 10, 'max_redirects' => 3],
            'request' => $request,
        ];
    }

    /** Gibt `127.0.0.1/32` mit dem Port frei (Admin, API), global oder für eine Kategorie. */
    private function releaseLoopback(int $port, ?int $categoryId = null): int
    {
        $response = $this->req('admin', 'POST', '/api/settings/internal-targets', ['kind' => 'cidr', 'value' => '127.0.0.1/32', 'port' => $port, 'category_id' => $categoryId, 'note' => 'Test']);
        Assert::assertSame(201, $response->getStatusCode(), $this->plain($response));
        $id = self::dig($this->json($response), 'target', 'id');
        Assert::assertIsInt($id);

        return $id;
    }

    private function removeRelease(int $id): void
    {
        $response = $this->req('admin', 'DELETE', '/api/settings/internal-targets/' . $id);
        Assert::assertSame(204, $response->getStatusCode());
    }

    /** Reiht einen manuellen Lauf ein; gibt die Laufnummer zurück. */
    private function enqueue(int $jobId, string $who = 'admin', string $kind = 'run'): int
    {
        $response = $this->req($who, 'POST', '/api/jobs/' . $jobId . '/' . $kind);
        Assert::assertSame(202, $response->getStatusCode(), $this->plain($response));
        $id = self::dig($this->json($response), 'run_id');
        Assert::assertIsInt($id);

        return $id;
    }

    /** Ersetzt die gespeicherte Anfrage direkt in der Datenbank (Altbestand, Manipulation): mit demselben Schlüssel verschlüsselt. */
    private function replacePayload(int $jobId, string $url): void
    {
        $payload = (new \Meridian\Runner\Http\HttpPayload($url))->toJson();
        $this->db()->execute('UPDATE jobs SET payload_enc = :p WHERE id = :id', ['p' => $this->box()->encrypt($payload), 'id' => $jobId]);
    }

    /**
     * @return array{status: string, note: ?string, output: ?string, http_status: ?int, trigger: string}
     */
    private function runRow(int $runId): array
    {
        $row = $this->db()->fetchOne('SELECT status, note, output, http_status, trigger FROM runs WHERE id = :id', ['id' => $runId]);
        Assert::assertNotNull($row);

        return [









            'api_tokens' => 'SELECT * FROM api_tokens',
            'audit_log' => 'SELECT * FROM audit_log',
            'categories' => 'SELECT * FROM categories',
            'http_internal_targets' => 'SELECT * FROM http_internal_targets',
            'http_status' => is_int($row['http_status']) ? $row['http_status'] : null,
            'job_channels' => 'SELECT * FROM job_channels',
            'job_notify_state' => 'SELECT * FROM job_notify_state',
            'job_tls' => 'SELECT * FROM job_tls',
            'jobs' => 'SELECT * FROM jobs',
            'live_streams' => 'SELECT * FROM live_streams',
            'login_failures' => 'SELECT * FROM login_failures',
            'monitors' => 'SELECT * FROM monitors',
            'note' => is_string($row['note']) ? $row['note'] : null,
            'notification_channels' => 'SELECT * FROM notification_channels',
            'notification_outbox' => 'SELECT * FROM notification_outbox',
            'output' => is_string($row['output']) ? $row['output'] : null,
            'recovery_codes' => 'SELECT * FROM recovery_codes',
            'role_permissions' => 'SELECT * FROM role_permissions',
            'roles' => 'SELECT * FROM roles',
            'run_log_chunks' => 'SELECT * FROM run_log_chunks',
            'run_stats_daily' => 'SELECT * FROM run_stats_daily',
            'runs' => 'SELECT * FROM runs',
            'scheduler_lease' => 'SELECT * FROM scheduler_lease',
            'schema_migrations' => 'SELECT * FROM schema_migrations',
            'sessions' => 'SELECT * FROM sessions',
            'settings' => 'SELECT * FROM settings',
            'shell_targets' => 'SELECT * FROM shell_targets',
            'status_page_items' => 'SELECT * FROM status_page_items',
            'status_pages' => 'SELECT * FROM status_pages',
'status' => is_string($row['status']) ? $row['status'] : '',
            'trigger' => is_string($row['trigger']) ? $row['trigger'] : '',
            'user_role_categories' => 'SELECT * FROM user_role_categories',
            'user_roles' => 'SELECT * FROM user_roles',
            'users' => 'SELECT * FROM users',
            'workers' => 'SELECT * FROM workers',
        );
        );
        ));
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            [PHP_BINARY, '-d', 'error_log=' . $this->errorLog, '-d', 'log_errors=1', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'zend.exception_ignore_args=0', dirname(__DIR__, 3) . '/bin/meridian', ...$args],
        ];
    {
    {
    {
    {
    {
    }
        }
    }
    }
        }
    }
            }
            }
            }
        }
        }
        }
    }
        } finally {
     *
     *
     *
     *
     *
     *
     * (Test-DNS für Rebinding und Namen).
     * @param array<string, string> $env
     * @param array<string, string> $env zusätzliche Umgebungsvariablen (z. B. HTTP_PROXY)
     * @param list<int> $jobIds
     * @param list<int> $runIds
     * @param list<string>          $args
     * @return array{code: int, out: string, err: string}
     * @return array{code: int, out: string, err: string}
     * @return array<string, literal-string>
     * @return list<RunEvent>
     * Der echte Dienst: `bin/meridian scheduler:run --once -vvv` mit expliziter Umgebung. Stdout, Stderr und das
     * Ein Takt im Prozess mit dem echten HttpRunner, dem echten CurlTransport und dem übergebenen Resolver
     * Jede Tabelle mit festem SQL-Literal (die Zuordnung zwingt dazu, neue Tabellen hier nachzutragen, sonst
     * Liest alle Lese-Endpunkte für alle Rollen und legt die Antworten in die Sammlung.
     * PHP-Fehlerprotokoll des Prozesses wandern in die Sammlung.
     * schlägt der Test fehl).
     */
     */
     */
     */
     */
    /**
    /**
    /**
    /**
    /**
    // ------------------------------------------------------------------------------------------------ Sammeln
    // ------------------------------------------------------------------------------------------------ Scheduler
        // Dieselben Endpunkte ohne Anmeldung: Fehlertexte dürfen nichts verraten.
        $clock = new SystemClock();
        $code = proc_close($process);
        $db = $this->db();
            $env + ['PATH' => '/usr/bin:/bin', 'MERIDIAN_DATA_DIR' => $this->dir, 'MERIDIAN_KEY_FILE' => $this->keyFile],
        $err = (string) stream_get_contents($pipes[2]);
        $lease = new SchedulerLease($db, $clock, SchedulerLease::newOwnerId());
            $lease,
        $out = (string) stream_get_contents($pipes[1]);
            $pipes,
        $process = proc_open(
        $result = $this->cli(['scheduler:run', '--once', '--inline-worker', $verbosity], $env, 'scheduler');
        $runners = new RunnerRegistry();
        $runners->register(JobType::Http, new HttpRunner(
        $scheduler = new Scheduler(
            $scheduler->shutdown();
            $this->box(),
        $this->capture($surface . '-stderr', $err);
        $this->capture($surface . '-stdout', $out);
            $this->req('admin', 'GET', $path);
                $this->req($who, 'GET', '/api/jobs/' . $jobId . '/runs?limit=100');
                $this->req($who, 'GET', '/api/jobs/' . $jobId);
                $this->req($who, 'GET', '/api/runs/' . $runId);
                $this->req($who, 'GET', $path);
            $this->req(null, 'GET', '/api/jobs/' . $jobId);
        Assert::assertIsResource($process);
            Assert::fail('scheduler:run endete mit Exit ' . $result['code'] . ': ' . $result['err'] . $result['out']);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        foreach (['/api/audit?limit=200', '/api/settings', '/api/settings/internal-targets'] as $path) {
            foreach (['/api/jobs', '/api/jobs?sort=next_run', '/api/jobs/limits', '/api/categories', '/api/auth/me', '/api/schedule/preview?cron=*/5%20*%20*%20*%20*&timezone=Europe/Berlin&count=3'] as $path) {
        foreach (['admin', 'operator', 'operatorA', 'observer'] as $who) {
            foreach ($jobIds as $jobId) {
        foreach ($jobIds as $jobId) {
            foreach ($runIds as $runId) {
        if ($result['code'] !== 0) {
            new \Meridian\Schedule\StaleRuns($db, $clock, $lease),
            new \Meridian\Schedule\Worker($db, $clock, new \Meridian\Schedule\WorkerIdentity($lease->owner(), \Meridian\Runner\JobType::Http), [\Meridian\Runner\JobType::Http], $runners, new DbRunAuthorizer($db, new UserRepository($db, new PasswordHasher()))),
            new CurlTransport('Meridian/test'),
            new DbHttpJobSource($db),
            new Planner($db, $clock, $lease),
            new StoredHttpRunSettings(new Settings($db, $clock)),
            new TargetGuard($resolver, new AddressPolicy(new DbInternalTargetSource($db))),
            null,
    private function cli(array $args, array $env = [], string $surface = 'cli'): array
    private function schedulerProcess(array $env = [], string $verbosity = '-vvv'): array
    private function schedulerTick(HostResolver $resolver): array
    private function sweepApi(array $jobIds, array $runIds): void
    private static function dumpQueries(): array
        return [
        return ['code' => $code, 'out' => $out, 'err' => $err];
        return $result;
            return $scheduler->tick(static fn (): bool => false);
        try {
        ];
    }

    /** Alle Tabellen als Text (jede Zeile als JSON) und die Rohbytes der Datenbankdateien. */
    private function captureDatabase(): void
    {
        $db = Connection::open($this->dir . '/meridian.sqlite');
        $queries = self::dumpQueries();
        $names = [];
        foreach ($db->fetchAll("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $row) {
            $names[] = is_string($row['name'] ?? null) ? $row['name'] : '';
        }
        Assert::assertSame(array_keys($queries), $names, 'Neue Tabelle: in dumpQueries() nachtragen, damit die Suite sie durchsucht.');
        $text = '';
        foreach ($queries as $table => $sql) {
            foreach ($db->fetchAll($sql) as $row) {
                $text .= $table . ': ' . json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        $this->captured['db-rows'] = $text;
        $raw = '';
        foreach (['', '-wal', '-journal'] as $suffix) {
            $file = $this->dir . '/meridian.sqlite' . $suffix;
            $raw .= is_file($file) ? (string) file_get_contents($file) : '';
        }
        $this->captured['db-file-bytes'] = $raw;
    }

    // ------------------------------------------------------------------------------------------------ Prüfen

    /**
     * Sammelt Datenbank und Fehlerprotokoll ein und prüft jede Ausgabestelle.
     *
     * @param list<string> $secrets
     */
    private function assertNoLeak(array $secrets, string $context = ''): void
    {
        $this->captureDatabase();
        $this->captured['php-error-log'] = (string) file_get_contents($this->errorLog);
        $this->assertNoLeakIn($this->captured, $secrets, $context);
    }

    /**
     * Das Geheimnis darf in keiner Schreibweise vorkommen: roh, URL-kodiert, base64 (auch URL-sicher), hex, als
     * JSON-Text und nicht einmal in Teilen von 12 Zeichen.
     *
     * @param array<string, string> $surfaces
     * @param list<string>          $secrets
     */
    private function assertNoLeakIn(array $surfaces, array $secrets, string $context = ''): void
    {
        $leak = self::findLeak($surfaces, $secrets);
        if ($leak !== null) {
            Assert::fail('LECK ' . $context . ': ' . $leak);
        }
    }

    /**
     * @param array<string, string> $surfaces
     * @param list<string>          $secrets
     *
     * @return string|null Beschreibung der ersten Fundstelle (nur die Umgebung, nie der ganze Text)
     */
    private static function findLeak(array $surfaces, array $secrets): ?string
    {
        foreach ($secrets as $secret) {
            Assert::assertGreaterThanOrEqual(24, strlen($secret), 'Test-Geheimnis zu kurz für die Teilstring-Prüfung');
            $forms = [
                'roh' => $secret,
                'rawurlencode' => rawurlencode($secret),
                'urlencode' => urlencode($secret),
                'base64' => base64_encode($secret),
                'base64url' => rtrim(strtr(base64_encode($secret), '+/', '-_'), '='),
                'hex' => bin2hex($secret),
                'json' => substr((string) json_encode($secret), 1, -1),
            ];
            for ($i = 0; $i + 12 <= strlen($secret); ++$i) {
                $forms['teil@' . $i] = substr($secret, $i, 12);
            }
            foreach ($surfaces as $name => $text) {
                foreach ($forms as $form => $needle) {
                    $at = strpos($text, $needle);
                    if ($at !== false) {
                        return sprintf(
                            'Geheimnis (%s) in Ausgabestelle „%s“ an Position %d: …%s…',
                            $form,
                            $name,
                            $at,
                            addcslashes(substr($text, max(0, $at - 60), 160), "\0..\37"),
                        );
                    }
                }
            }
        }

        return null;
    }

    /**
     * Zufällig, aber offensichtlich unecht: `TEST-SECRET-do-not-use-<teil>-<hex>`; aus Teilen zur Laufzeit gebaut
     * (kein Literal im Quelltext, damit Push-Protection und gitleaks nicht anschlagen).
     */
    private static function token(string $part): string
    {
        return implode('-', ['TEST', 'SECRET', 'do', 'not', 'use', $part, bin2hex(random_bytes(6))]);
    }

    /**
     * @return array<string, string>
     */
    private function newSecrets(): array
    {
        $out = [];
        foreach (['path', 'query', 'header', 'header2', 'body', 'body2'] as $part) {
            $out[$part] = self::token($part);
        }

        return $out;
    }
}
