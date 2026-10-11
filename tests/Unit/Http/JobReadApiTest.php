<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Http;

use Meridian\Security\CategoryScope;
use Meridian\Settings\SettingKey;
use Meridian\Settings\Settings;
use Meridian\Tests\Support\JobApiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lesende Job-API (S5): Rollen-Matrix, IDOR, Feld-Allowlist (H2), gleiche Sichtbarkeit in Liste und Detail.
 */
final class JobReadApiTest extends TestCase
{
    use JobApiHarness;

    protected function setUp(): void
    {
        (new \Meridian\Settings\Settings($this->db(), $this->clock()))->set(\Meridian\Settings\SettingKey::HttpDisplayPath, 'auto', null);
        $this->seedJobs();
    }

    // --- Anmeldung -------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function readPaths(): array
    {
        return [
            'Liste' => ['/api/jobs'],
            'Detail' => ['/api/jobs/1'],
            'Verlauf' => ['/api/jobs/1/runs'],
            'Lauf' => ['/api/runs/1'],
            'Vorschau' => ['/api/schedule/preview?cron=*%20*%20*%20*%20*&timezone=UTC'],
            'Grenzen' => ['/api/jobs/limits'],
            'Kategorien' => ['/api/categories'],
        ];
    }

    #[DataProvider('readPaths')]
    public function testEveryReadEndpointNeedsLogin(string $path): void
    {
        $anonymous = $this->api(null, 'GET', $path);
        self::assertSame(401, $anonymous->getStatusCode());
        self::assertSame('{"error":"Nicht angemeldet."}', $anonymous->getContent());
        self::assertSame(401, $this->call('GET', $path, [], [], [self::COOKIE => 'gefaelscht'])->getStatusCode());
    }

    #[DataProvider('readPaths')]
    public function testDeactivatedUserLosesAccessAtOnce(string $path): void
    {
        self::assertNotSame(401, $this->api('admin', 'GET', $path)->getStatusCode());
        $this->db()->execute("UPDATE users SET is_active = 0 WHERE username = 'admin'");

        self::assertSame(401, $this->api('admin', 'GET', $path)->getStatusCode());
    }

    #[DataProvider('readPaths')]
    public function testResponsesAreNotCacheable(string $path): void
    {
        self::assertStringContainsString('no-store', (string) $this->api('admin', 'GET', $path)->headers->get('Cache-Control'));
    }

    // --- Liste und Detail: Rollen-Matrix ----------------------------------------------------------------

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function listVisibility(): array
    {
        return [
            'Admin' => ['admin', ['JA', 'JB', 'JN']],
            'Operator' => ['operator', ['JA', 'JB', 'JN']],
            'Operator nur A' => ['operatorA', ['JA']],
            'Beobachter' => ['observer', ['JA', 'JB', 'JN']],
            'Beobachter nur A' => ['observerA', ['JA']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('listVisibility')]
    public function testListIsCappedServerSideToTheScope(string $who, array $expected): void
    {
        $response = $this->api($who, 'GET', '/api/jobs');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($expected, self::namesOf($this->jobsOf($response)));
        self::assertFalse($this->json($response)['truncated'] ?? null);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('listVisibility')]
    public function testDetailAndListShareTheSameVisibility(string $who, array $expected): void
    {
        foreach ($this->jobIds as $name => $id) {
            $detail = $this->api($who, 'GET', '/api/jobs/' . $id);
            $visible = in_array($name, $expected, true);

            self::assertSame($visible ? 200 : 404, $detail->getStatusCode(), $who . ' Detail ' . $name);
            if ($visible) {
                self::assertSame($name, self::dig($this->json($detail), 'job', 'name'));
            } else {
                self::assertSame('{"error":"Job nicht gefunden."}', $detail->getContent(), 'keine Daten, kein Hinweis auf die Existenz');
            }
        }
    }

    public function testRequestedCategoryFilterNeverWidensTheScope(): void
    {
        $asOperatorA = fn (string $query): array => self::namesOf($this->jobsOf($this->api('operatorA', 'GET', '/api/jobs' . $query)));

        self::assertSame([], $asOperatorA('?category_id=2'), 'category=B für den A-Bereich bleibt leer');
        self::assertSame(['JA'], $asOperatorA('?category_id=1'));
        self::assertSame(['JA'], $asOperatorA('?all=1'), 'unbekannte Parameter sind keine Berechtigung');
        self::assertSame(['JA'], $asOperatorA('?all=1&category=B&owner=1'), 'category=, all=1, owner= sind keine Berechtigung');
        self::assertSame(['JB'], self::namesOf($this->jobsOf($this->api('admin', 'GET', '/api/jobs?category_id=2'))));
    }

    public function testListFiltersAndSorting(): void
    {
        $this->db()->execute("UPDATE jobs SET is_enabled = 0 WHERE name = 'JB'");
        $this->db()->execute("UPDATE jobs SET next_run_at = '2026-10-07T10:00:00+00:00' WHERE name = 'JN'");
        $this->db()->execute("UPDATE jobs SET next_run_at = '2026-10-07T09:00:00+00:00' WHERE name = 'JA'");
        $names = fn (string $query): array => array_map(static fn (array $job): mixed => $job['name'] ?? null, $this->jobsOf($this->api('admin', 'GET', '/api/jobs' . $query)));

        self::assertSame(['JA', 'JB', 'JN'], $names(''));
        self::assertSame(['JA', 'JN', 'JB'], $names('?sort=next_run'));
        self::assertSame(['JA', 'JN'], $names('?enabled=1'));
        self::assertSame([], $names('?type=shell'));
        self::assertSame(['JA', 'JB', 'JN'], $names('?type=http'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidListQueries(): array
    {
        return [
            'Art' => ['?type=ftp'],
            'Art als Liste' => ['?type[]=http'],
            'Kategorie Text' => ['?category_id=abc'],
            'Kategorie null' => ['?category_id=0'],
            'Sortierung' => ['?sort=owner'],
            'enabled' => ['?enabled=yes'],
        ];
    }

    #[DataProvider('invalidListQueries')]
    public function testInvalidListQueriesAreRejectedWith422(string $query): void
    {
        $response = $this->api('admin', 'GET', '/api/jobs' . $query);

        self::assertSame(422, $response->getStatusCode());
        self::assertIsArray(self::dig($this->json($response), 'fields'));
    }

    public function testListSummaryHasNoDisplayUrlAndOnlyTheTarget(): void
    {
        $job = $this->jobsOf($this->api('admin', 'GET', '/api/jobs'))[0];

        self::assertSame(['target' => 'https://img.example.org'], $job['http']);
        self::assertArrayNotHasKey('display_url', $job['http']);
        self::assertStringNotContainsString('display_url', (string) $this->api('admin', 'GET', '/api/jobs')->getContent());
    }

    public function testDetailHasTheDocumentedShapeAndCanFlags(): void
    {
        $this->db()->execute("INSERT INTO runs (job_id, trigger, status, finished_at, duration_ms, http_status) VALUES (:j, 'schedule', 'ok', '2026-10-07T07:00:00+00:00', 2210, 200)", ['j' => $this->jobIds['JA']]);
        $this->db()->execute("INSERT INTO runs (job_id, trigger, status) VALUES (:j, 'schedule', 'running')", ['j' => $this->jobIds['JA']]);

        $job = self::dig($this->json($this->api('admin', 'GET', '/api/jobs/' . $this->jobIds['JA'])), 'job');

        self::assertIsArray($job);
        self::assertSame(['id', 'name', 'type', 'category', 'owner', 'cron', 'timezone', 'next_run_at', 'is_enabled', 'overlap_policy', 'retry_count', 'retry_delay_seconds', 'catch_up', 'expires_at', 'notify', 'last_run', 'running', 'can', 'created_at', 'updated_at', 'http'], array_keys($job));
        self::assertSame(['id' => 1, 'name' => 'A'], $job['category']);
        self::assertSame(['id' => 1, 'display_name' => 'Admin'], self::dig($this->json($this->api('admin', 'GET', '/api/jobs/' . $this->jobIds['JA'])), 'job', 'owner') ?? null) ;
        self::assertTrue($job['running']);
        self::assertSame(['id' => 1, 'status' => 'ok', 'trigger' => 'schedule', 'finished_at' => '2026-10-07T07:00:00+00:00', 'duration_ms' => 2210, 'http_status' => 200], $job['last_run']);
        self::assertSame(
            ['method', 'timeout_seconds', 'expected_status', 'max_redirects', 'store_response', 'target', 'has_request', 'display_url', 'has_url', 'has_headers', 'header_count', 'has_body'],
            array_keys((array) $job['http']),
        );
        self::assertSame('https://img.example.org/tools/api/cron.php?key=••••', self::dig($job, 'http', 'display_url'));
        self::assertSame(['edit' => true, 'run' => true], $job['can']);
    }

    public function testCanFlagsFollowTheRole(): void
    {
        $can = fn (string $who): mixed => self::dig($this->json($this->api($who, 'GET', '/api/jobs/' . $this->jobIds['JA'])), 'job', 'can');

        self::assertSame(['edit' => true, 'run' => true], $can('operator'));
        self::assertSame(['edit' => true, 'run' => true], $can('operatorA'));
        self::assertSame(['edit' => false, 'run' => false], $can('observer'));
    }

    // --- Geheimnisse und H2 ------------------------------------------------------------------------

    public function testNoSecretOfTheRequestAppearsInAnyReadResponse(): void
    {
        $secrets = [self::testSecret('path'), self::testSecret('query'), self::testSecret('header'), self::testSecret('body')];
        $draft = $this->validator()->validate(self::jobInput([
            'name' => 'Geheim',
            'http' => ['method' => 'POST'],
            'request' => [
                'url' => 'https://api.example.org/hooks/' . $secrets[0] . '/run?key=' . $secrets[1],
                'headers' => [['name' => 'Authorization', 'value' => 'Bearer ' . $secrets[2]], ['name' => 'X-Token-' . 'Name', 'value' => 'v-' . $secrets[2]]],
                'body' => '{"token":"' . $secrets[3] . '"}',
            ],
        ]), CategoryScope::everything(), null);
        $id = $this->jobRepository()->insert($draft, 1);
        $this->db()->execute("INSERT INTO runs (job_id, trigger, status, output) VALUES (:j, 'schedule', 'ok', 'fertig')", ['j' => $id]);

        $bodies = [];
        foreach (['/api/jobs', '/api/jobs/' . $id, '/api/jobs/' . $id . '/runs', '/api/runs/' . $this->db()->lastInsertId(), '/api/jobs/limits', '/api/categories'] as $path) {
            $bodies[$path] = $this->plain($this->api('admin', 'GET', $path));
        }
        self::assertStringContainsString('https://api.example.org/hooks/••••/run?key=••••', $bodies['/api/jobs/' . $id]);
        foreach ($bodies as $path => $body) {
            foreach ($secrets as $secret) {
                foreach ([$secret, rawurlencode($secret), urlencode($secret), base64_encode($secret), json_encode($secret)] as $form) {
                    self::assertStringNotContainsString((string) $form, $body, $path);
                }
            }
            self::assertStringNotContainsString('Authorization', $body, 'Header-Namen erscheinen nie: ' . $path);
            self::assertStringNotContainsString('X-Token', $body, $path);
        }
        $detail = (array) self::dig($this->json($this->api('admin', 'GET', '/api/jobs/' . $id)), 'job', 'http');
        self::assertSame(2, $detail['header_count']);
        self::assertTrue($detail['has_headers']);
        self::assertTrue($detail['has_body']);
    }

    public function testRunsNeverExposeWorkerOrHeartbeat(): void
    {
        $worker = 'WORKER-' . bin2hex(random_bytes(6));
        $this->db()->execute(
            "INSERT INTO runs (job_id, trigger, status, started_at, heartbeat_at, worker, output, note, started_by) VALUES (:j, 'manual', 'running', '2026-10-07T08:00:00+00:00', '2026-10-07T08:00:09+00:00', :w, 'läuft', 'Notiz', 1)",
            ['j' => $this->jobIds['JA'], 'w' => $worker],
        );
        $runId = $this->db()->lastInsertId();

        foreach (['/api/jobs/' . $this->jobIds['JA'] . '/runs', '/api/runs/' . $runId, '/api/jobs/' . $this->jobIds['JA'], '/api/jobs'] as $path) {
            $body = (string) $this->api('admin', 'GET', $path)->getContent();
            self::assertStringNotContainsString($worker, $body, $path);
            self::assertStringNotContainsString('worker', $body, $path);
            self::assertStringNotContainsString('heartbeat', $body, $path);
            self::assertStringNotContainsString('2026-10-07T08:00:09', $body, $path);
        }
        $run = (array) self::dig($this->json($this->api('admin', 'GET', '/api/runs/' . $runId)), 'run');
        self::assertSame(['id', 'job_id', 'trigger', 'status', 'attempt', 'scheduled_for', 'started_at', 'finished_at', 'duration_ms', 'http_status', 'note', 'output', 'started_by', 'exit_code', 'cancel_requested_at', 'cancelled_by', 'output_bytes', 'live'], array_keys($run));
        self::assertSame(['id' => 1, 'display_name' => 'Admin'], $run['started_by']);
    }

    public function testRunTextsAreMaskedBeforeLeavingTheSystem(): void
    {
        $secret = self::testSecret('output');
        $this->db()->execute("INSERT INTO runs (job_id, trigger, status, output, note) VALUES (:j, 'schedule', 'failed', :o, :n)", ['j' => $this->jobIds['JA'], 'o' => 'Antwort: token=' . $secret . ' Ende', 'n' => 'password=' . $secret]);
        $runId = $this->db()->lastInsertId();

        $body = $this->plain($this->api('admin', 'GET', '/api/runs/' . $runId));

        self::assertStringNotContainsString($secret, $body);
        self::assertStringContainsString('token=••••', $body);
    }

    // --- Verlauf und Lauf --------------------------------------------------------------------------------

    private function seedRuns(): void
    {
        foreach (['JA', 'JB', 'JN'] as $name) {
            foreach (['ok', 'failed', 'ok'] as $status) {
                $this->db()->execute("INSERT INTO runs (job_id, trigger, status, output) VALUES (:j, 'schedule', :s, :o)", ['j' => $this->jobIds[$name], 's' => $status, 'o' => 'Ausgabe von ' . $name]);
            }
        }
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function jobVisibility(): array
    {
        return [
            'Admin' => ['admin', ['JA', 'JB', 'JN']],
            'Operator' => ['operator', ['JA', 'JB', 'JN']],
            'Operator nur A' => ['operatorA', ['JA']],
            'Beobachter' => ['observer', ['JA', 'JB', 'JN']],
        ];
    }

    /**
     * @param list<string> $visible
     */
    #[DataProvider('jobVisibility')]
    public function testHistoryAndRunFollowTheJobsVisibility(string $who, array $visible): void
    {
        $this->seedRuns();
        foreach ($this->jobIds as $name => $jobId) {
            $history = $this->api($who, 'GET', '/api/jobs/' . $jobId . '/runs');
            if (!in_array($name, $visible, true)) {
                self::assertSame(404, $history->getStatusCode(), $who . ' Verlauf ' . $name);
                self::assertStringNotContainsString('Ausgabe', (string) $history->getContent());
                continue;
            }
            self::assertSame(200, $history->getStatusCode());
            $runs = self::dig($this->json($history), 'runs');
            self::assertIsArray($runs);
            self::assertCount(3, $runs);
            self::assertArrayNotHasKey('output', (array) $runs[0], 'Verlaufsliste ohne Ausgabe');
        }

        $rows = $this->db()->fetchAll('SELECT r.id, j.name FROM runs r JOIN jobs j ON j.id = r.job_id');
        foreach ($rows as $row) {
            $response = $this->api($who, 'GET', '/api/runs/' . self::intOf($row['id']));
            $expected = in_array($row['name'], $visible, true);
            self::assertSame($expected ? 200 : 404, $response->getStatusCode(), $who . ' Lauf von ' . self::textOf($row['name']));
            if ($expected) {
                self::assertSame('Ausgabe von ' . self::textOf($row['name']), self::dig($this->json($response), 'run', 'output'));
            } else {
                self::assertStringNotContainsString('Ausgabe', (string) $response->getContent());
            }
        }
    }

    public function testHistoryPagingAndFilters(): void
    {
        $this->seedRuns();
        $path = '/api/jobs/' . $this->jobIds['JA'] . '/runs';

        $first = $this->json($this->api('admin', 'GET', $path . '?limit=2'));
        $runs = (array) ($first['runs'] ?? []);
        self::assertCount(2, $runs);
        $cursor = $first['next_before_id'] ?? null;
        self::assertIsInt($cursor);
        self::assertSame($cursor, self::dig($runs, 1, 'id'));

        $second = $this->json($this->api('admin', 'GET', $path . '?limit=2&before_id=' . $cursor));
        self::assertCount(1, (array) $second['runs']);
        self::assertNull($second['next_before_id'], 'letzte Seite hat keinen Cursor');
        self::assertCount(1, (array) ($this->json($this->api('admin', 'GET', $path . '?status=failed'))['runs'] ?? []));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidHistoryQueries(): array
    {
        return [
            'limit 0' => ['?limit=0'],
            'limit 101' => ['?limit=101'],
            'limit Liste' => ['?limit[]=1'],
            'before_id Text' => ['?before_id=x'],
            'status unbekannt' => ['?status=done'],
        ];
    }

    #[DataProvider('invalidHistoryQueries')]
    public function testInvalidHistoryQueriesAre422(string $query): void
    {
        self::assertSame(422, $this->api('admin', 'GET', '/api/jobs/' . $this->jobIds['JA'] . '/runs' . $query)->getStatusCode());
    }

    public function testUnknownAndMalformedIdsAre404(): void
    {
        foreach (['/api/jobs/9999', '/api/jobs/0', '/api/jobs/abc', '/api/jobs/-1', '/api/jobs/01', '/api/runs/9999', '/api/jobs/9999/runs'] as $path) {
            self::assertSame(404, $this->api('admin', 'GET', $path)->getStatusCode(), $path);
        }
    }

    // --- Vorschau, Grenzen, Kategorien -------------------------------------------------------------------

    public function testPreviewListsNextRunsInTheJobsTimezone(): void
    {
        $this->clock()->advance(0);

        $response = $this->api('observer', 'GET', '/api/schedule/preview?cron=0%209%20*%20*%20*&timezone=Europe/Berlin&count=3');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['2026-10-08T09:00:00+02:00', '2026-10-09T09:00:00+02:00', '2026-10-10T09:00:00+02:00'], self::dig($this->json($response), 'runs'));
        self::assertCount(5, (array) self::dig($this->json($this->api('admin', 'GET', '/api/schedule/preview?cron=*/5%20*%20*%20*%20*&timezone=UTC')), 'runs'), 'Standard 5');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidPreviews(): array
    {
        return [
            'count 11' => ['?cron=*%20*%20*%20*%20*&timezone=UTC&count=11', 'count'],
            'count 0' => ['?cron=*%20*%20*%20*%20*&timezone=UTC&count=0', 'count'],
            'cron fehlt' => ['?timezone=UTC', 'cron'],
            'cron ungültig' => ['?cron=61%20*%20*%20*%20*&timezone=UTC', 'cron'],
            'cron zu lang' => ['?cron=' . '*' . '&timezone=UTC&x=' . '', 'cron'],
            'Zeitzone fehlt' => ['?cron=*%20*%20*%20*%20*', 'timezone'],
            'Zeitzone unbekannt' => ['?cron=*%20*%20*%20*%20*&timezone=Mars/Olympus', 'timezone'],
            'ohne Termin' => ['?cron=0%200%2030%202%20*&timezone=UTC', 'cron'],
        ];
    }

    #[DataProvider('invalidPreviews')]
    public function testInvalidPreviewsAre422WithAFixedMessage(string $query, string $field): void
    {
        $response = $this->api('admin', 'GET', '/api/schedule/preview' . $query);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey($field, (array) self::dig($this->json($response), 'fields'));
        self::assertStringNotContainsString('Mars', (string) $response->getContent(), 'Meldung ohne Eingabewert');
    }

    public function testLimitsShowCurrentExecutionLimits(): void
    {
        $settings = new Settings($this->db(), $this->clock());
        $settings->set(SettingKey::HttpMaxTimeout, 120, 1);
        $settings->set(SettingKey::HttpDisplayPath, 'hidden', 1);

        $response = $this->api('observer', 'GET', '/api/jobs/limits');

        self::assertSame(['max_timeout_seconds' => 120, 'shell_max_timeout_seconds' => 3600, 'response_storage' => 'off', 'min_interval_seconds' => 10], $this->json($response));
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function categoryMatrix(): array
    {
        return [
            'Admin view' => ['admin', 'jobs.view', ['A', 'B']],
            'Admin edit' => ['admin', 'jobs.edit_http', ['A', 'B']],
            'Operator view' => ['operator', 'jobs.view', ['A', 'B']],
            'Operator nur A view' => ['operatorA', 'jobs.view', ['A']],
            'Operator nur A edit' => ['operatorA', 'jobs.edit_http', ['A']],
            'Beobachter view' => ['observer', 'jobs.view', ['A', 'B']],
            'Beobachter edit' => ['observer', 'jobs.edit_http', []],
            'Beobachter nur A view' => ['observerA', 'jobs.view', ['A']],
            'Beobachter nur A edit' => ['observerA', 'jobs.edit_http', []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('categoryMatrix')]
    public function testCategoriesFollowTheRequestedPermission(string $who, string $permission, array $expected): void
    {
        $response = $this->api($who, 'GET', '/api/categories?permission=' . $permission);

        self::assertSame(200, $response->getStatusCode());
        $names = array_map(static fn (mixed $c): mixed => is_array($c) ? ($c['name'] ?? null) : null, (array) ($this->json($response)['categories'] ?? []));
        self::assertSame($expected, $names);
    }

    public function testCategoriesDefaultToViewAndRejectOtherPermissions(): void
    {
        self::assertCount(2, (array) ($this->json($this->api('observer', 'GET', '/api/categories'))['categories'] ?? []));
        foreach (['users.manage', 'x', 'jobs.run', ''] as $permission) {
            self::assertSame(422, $this->api('admin', 'GET', '/api/categories?permission=' . $permission)->getStatusCode(), $permission);
        }
        self::assertSame(422, $this->api('admin', 'GET', '/api/categories?permission[]=jobs.view')->getStatusCode());
    }

    public function testAUserWithoutAnyViewRightGetsNothing(): void
    {
        $this->db()->execute('DELETE FROM user_role_categories');
        $this->as('operatorA');
        $this->db()->execute("UPDATE user_roles SET all_categories = 0 WHERE user_id = (SELECT id FROM users WHERE username = 'operatorA')");
        $this->db()->execute('DELETE FROM user_role_categories');

        self::assertSame(403, $this->api('operatorA', 'GET', '/api/jobs')->getStatusCode(), 'eine leere Kategorieliste heißt nie „alles“');
        self::assertSame(403, $this->api('operatorA', 'GET', '/api/jobs/limits')->getStatusCode());
        self::assertSame(403, $this->api('operatorA', 'GET', '/api/schedule/preview?cron=*%20*%20*%20*%20*&timezone=UTC')->getStatusCode());
        self::assertSame(404, $this->api('operatorA', 'GET', '/api/jobs/' . $this->jobIds['JA'])->getStatusCode());
        self::assertSame([], (array) ($this->json($this->api('operatorA', 'GET', '/api/categories'))['categories'] ?? null));
    }

    private static function intOf(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }

    private static function textOf(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
