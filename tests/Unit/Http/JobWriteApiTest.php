<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Http;

use Meridian\Runner\Http\HttpPayload;
use Meridian\Settings\SettingKey;
use Meridian\Settings\Settings;
use Meridian\Tests\Support\JobApiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Schreibende Job-API (S6): Rollen-Matrix, CSRF, Anfrage nur als Ganzes ersetzen, H6, Audit, Leak-Tests.
 */
final class JobWriteApiTest extends TestCase
{
    use JobApiHarness;

    protected function setUp(): void
    {
        (new \Meridian\Settings\Settings($this->db(), $this->clock()))->set(\Meridian\Settings\SettingKey::HttpDisplayPath, 'auto', null);
        $this->seedJobs();
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function body(array $override = []): array
    {
        return $override + [
            'name' => 'Neu',
            'type' => 'http',
            'category_id' => 1,
            'cron' => '*/10 * * * *',
            'timezone' => 'Europe/Berlin',
            'request' => ['url' => 'https://hooks.example.org/status'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function job(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $job = $this->json($response)['job'] ?? null;
        self::assertIsArray($job);
        /** @var array<string, mixed> $job */

        return $job;
    }

    // --- Anlegen: Rollen-Matrix ----------------------------------------------------------------------

    /**
     * @return array<string, array{?string, int, int|null}> Rolle, Status, Kategorie der Anfrage
     */
    public static function createMatrix(): array
    {
        return [
            'anonym' => [null, 401, 1],
            'Admin in A' => ['admin', 201, 1],
            'Admin ohne Kategorie' => ['admin', 201, null],
            'Operator in B' => ['operator', 201, 2],
            'Operator ohne Kategorie' => ['operator', 201, null],
            'Operator nur A in A' => ['operatorA', 201, 1],
            'Operator nur A in B' => ['operatorA', 422, 2],
            'Operator nur A ohne Kategorie' => ['operatorA', 422, null],
            'Beobachter' => ['observer', 403, 1],
            'Beobachter nur A' => ['observerA', 403, 1],
        ];
    }

    #[DataProvider('createMatrix')]
    public function testCreateMatrix(?string $who, int $status, ?int $category): void
    {
        $before = $this->jobCount();

        $response = $this->api($who, 'POST', '/api/jobs', $this->body(['category_id' => $category]));

        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($status === 201 ? $before + 1 : $before, $this->jobCount(), 'nur ein erfolgreiches Anlegen schreibt');
        if ($status === 422) {
            self::assertArrayHasKey('category_id', (array) $this->json($response)['fields']);
        }
    }

    public function testCreatedJobHasOwnerAuditAndAnInitialNextRun(): void
    {
        $response = $this->api('operator', 'POST', '/api/jobs', $this->body());

        $job = $this->job($response);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Neu', $job['name']);
        self::assertSame('Operator', self::dig($job, 'owner', 'display_name'));
        self::assertSame('2026-10-07T08:10:00+00:00', $job['next_run_at'], 'H6: reschedule() nach dem Commit, nächster Termin nach „jetzt“ (08:00 UTC)');
        self::assertTrue($job['is_enabled']);
        self::assertSame(['edit' => true, 'run' => true], $job['can']);
        self::assertSame('https://hooks.example.org/status', self::dig($job, 'http', 'display_url'));
        $row = $this->rawJob(self::intOf($job['id']));
        self::assertSame('2026-10-07T08:10:00+00:00', $row['next_run_at']);
        self::assertContains('job.created', $this->auditActions());
        $audit = $this->db()->fetchOne("SELECT user_id, target FROM audit_log WHERE action = 'job.created'");
        self::assertSame(['user_id' => self::intOf(self::dig($this->db()->fetchOne("SELECT id FROM users WHERE username = 'operator'"), 'id')), 'target' => 'job:' . self::intOf($job['id']) . ' Neu'], $audit);
    }

    public function testDisabledJobGetsNoNextRun(): void
    {
        $job = $this->job($this->api('admin', 'POST', '/api/jobs', $this->body(['is_enabled' => false])));

        self::assertNull($job['next_run_at']);
        self::assertFalse($job['is_enabled']);
    }

    public function testCreateStoresEncryptedPayloadAndClassifiedConfig(): void
    {
        $secret = self::testSecret('pay');
        $job = $this->job($this->api('admin', 'POST', '/api/jobs', $this->body([
            'http' => ['method' => 'POST', 'timeout_seconds' => 45, 'expected_status' => '200,204', 'max_redirects' => 1, 'store_response' => 'off'],
            'request' => ['url' => 'https://api.example.org/hooks/' . $secret, 'headers' => [['name' => 'X-Api-Key', 'value' => $secret]], 'body' => 'a=' . $secret],
            'catch_up' => true,
            'overlap_policy' => 'queue',
            'retry_count' => 3,
            'retry_delay_seconds' => 120,
        ])));

        $row = $this->rawJob(self::intOf($job['id']));
        $payload = $row['payload_enc'];
        self::assertIsString($payload);
        self::assertStringStartsWith('v1:', $payload);
        $plain = HttpPayload::fromJson($this->box()->decrypt($payload));
        self::assertSame('https://api.example.org/hooks/' . $secret, $plain->url()->toUrl());
        self::assertSame([['X-Api-Key', $secret]], $plain->headers());
        self::assertSame('a=' . $secret, $plain->body());
        self::assertSame(1, $row['catch_up']);
        self::assertSame('queue', $row['overlap_policy']);
        self::assertSame(3, $row['retry_count']);
        self::assertSame(120, $row['retry_delay_seconds']);
        self::assertSame('POST', self::dig($job, 'http', 'method'));
        self::assertSame('200,204', self::dig($job, 'http', 'expected_status'));
        self::assertSame('off', self::dig($job, 'http', 'store_response'));
    }

    // --- Ändern -----------------------------------------------------------------------------------------

    /**
     * @return array<string, array{?string, string, int}>
     */
    public static function updateMatrix(): array
    {
        return [
            'anonym' => [null, 'JA', 401],
            'Admin A' => ['admin', 'JA', 200],
            'Admin ohne Kategorie' => ['admin', 'JN', 200],
            'Operator B' => ['operator', 'JB', 200],
            'Operator nur A auf A' => ['operatorA', 'JA', 200],
            'Operator nur A auf B' => ['operatorA', 'JB', 404],
            'Operator nur A auf ohne' => ['operatorA', 'JN', 404],
            'Beobachter' => ['observer', 'JA', 403],
            'Beobachter nur A auf B' => ['observerA', 'JB', 404],
        ];
    }

    #[DataProvider('updateMatrix')]
    public function testUpdateMatrix(?string $who, string $job, int $status): void
    {
        $id = $this->jobIds[$job];
        $before = $this->rawJob($id);

        $response = $this->api($who, 'PUT', '/api/jobs/' . $id, ['name' => 'Umbenannt']);

        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        $after = $this->rawJob($id);
        self::assertSame($status === 200 ? 'Umbenannt' : $before['name'], $after['name']);
        if ($status !== 200) {
            self::assertSame($before, $after, 'nichts geändert');
        }
    }

    public function testCategoryMoveNeedsTheRightInBothCategories(): void
    {
        // A → B für Operator nur A: 422 (Kategorie nicht erlaubt), Job bleibt in A.
        $response = $this->api('operatorA', 'PUT', '/api/jobs/' . $this->jobIds['JA'], ['category_id' => 2]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(1, $this->rawJob($this->jobIds['JA'])['category_id']);

        // B → A für Operator nur A: der Job ist nicht sichtbar → 404 (und bleibt in B).
        self::assertSame(404, $this->api('operatorA', 'PUT', '/api/jobs/' . $this->jobIds['JB'], ['category_id' => 1])->getStatusCode());
        self::assertSame(2, $this->rawJob($this->jobIds['JB'])['category_id']);

        // Uneingeschränkt: A → B geht, auch auf „ohne Kategorie“.
        self::assertSame(200, $this->api('operator', 'PUT', '/api/jobs/' . $this->jobIds['JA'], ['category_id' => 2])->getStatusCode());
        self::assertSame(2, $this->rawJob($this->jobIds['JA'])['category_id']);
        self::assertSame(200, $this->api('operator', 'PUT', '/api/jobs/' . $this->jobIds['JA'], ['category_id' => null])->getStatusCode());
        self::assertNull($this->rawJob($this->jobIds['JA'])['category_id']);
        $audit = $this->db()->fetchAll("SELECT target FROM audit_log WHERE action = 'job.updated' ORDER BY id");
        self::assertSame('job:' . $this->jobIds['JA'] . ' JA; geändert: Kategorie A → B', self::dig($audit, 0, 'target'));
    }

    public function testUpdateWithoutRequestKeepsUrlHeadersAndBody(): void
    {
        $secret = self::testSecret('keep');
        $created = $this->job($this->api('admin', 'POST', '/api/jobs', $this->body([
            'http' => ['method' => 'POST'],
            'request' => ['url' => 'https://api.example.org/x?token=' . $secret, 'headers' => [['name' => 'Authorization', 'value' => 'Bearer ' . $secret]], 'body' => 'b=' . $secret],
        ])));
        $id = self::intOf($created['id']);
        $before = $this->rawJob($id);

        $response = $this->api('operator', 'PUT', '/api/jobs/' . $id, ['name' => 'Anders', 'http' => ['timeout_seconds' => 12]]);

        $job = $this->job($response);
        self::assertSame(200, $response->getStatusCode());
        $after = $this->rawJob($id);
        self::assertSame($before['payload_enc'], $after['payload_enc'], 'payload_enc unberührt');
        self::assertSame(12, self::dig($job, 'http', 'timeout_seconds'));
        self::assertSame('POST', self::dig($job, 'http', 'method'));
        self::assertSame(self::dig($created, 'http', 'display_url'), self::dig($job, 'http', 'display_url'));
        self::assertTrue(self::dig($job, 'http', 'has_headers'));
        self::assertTrue(self::dig($job, 'http', 'has_body'));
        self::assertSame($before['next_run_at'], $after['next_run_at'], 'Zeitplan unverändert: Termin bleibt');
    }

    public function testReplacingTheRequestReplacesUrlHeadersAndBodyTogether(): void
    {
        $created = $this->job($this->api('admin', 'POST', '/api/jobs', $this->body([
            'http' => ['method' => 'POST'],
            'request' => ['url' => 'https://api.example.org/x', 'headers' => [['name' => 'Authorization', 'value' => 'Bearer ' . self::testSecret('old')]], 'body' => 'alt'],
        ])));
        $id = self::intOf($created['id']);

        $response = $this->api('admin', 'PUT', '/api/jobs/' . $id, ['request' => ['url' => 'https://elsewhere.example.org/y']]);

        $job = $this->job($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse(self::dig($job, 'http', 'has_headers'), 'fehlende Header = keine Header');
        self::assertFalse(self::dig($job, 'http', 'has_body'), 'fehlender Body = kein Body');
        self::assertSame('https://elsewhere.example.org', self::dig($job, 'http', 'target'));
        $plain = HttpPayload::fromJson($this->box()->decrypt(self::textOf($this->rawJob($id)['payload_enc'])));
        self::assertSame([], $plain->headers(), 'der gespeicherte Authorization-Header wandert nicht mit zum neuen Ziel');
        self::assertNull($plain->body());
        $target = self::textOf(self::dig($this->db()->fetchOne("SELECT target FROM audit_log WHERE action = 'job.updated' ORDER BY id DESC LIMIT 1"), 'target'));
        self::assertStringEndsWith('; geändert: Anfrage ersetzt', $target);
    }

    public function testGetWithStoredBodyIsRejectedUnlessTheRequestIsReplaced(): void
    {
        $created = $this->job($this->api('admin', 'POST', '/api/jobs', $this->body(['http' => ['method' => 'POST'], 'request' => ['url' => 'https://api.example.org/x', 'body' => 'b']])));
        $id = self::intOf($created['id']);

        $rejected = $this->api('admin', 'PUT', '/api/jobs/' . $id, ['http' => ['method' => 'GET']]);
        self::assertSame(422, $rejected->getStatusCode());
        self::assertArrayHasKey('http.method', (array) $this->json($rejected)['fields']);
        self::assertStringContainsString('"method":"POST"', self::textOf($this->rawJob($id)['config_json']), 'abgelehnt: nichts geändert');

        $ok = $this->api('admin', 'PUT', '/api/jobs/' . $id, ['http' => ['method' => 'GET'], 'request' => ['url' => 'https://api.example.org/x']]);
        self::assertSame(200, $ok->getStatusCode());
    }

    public function testScheduleChangeResetsAndRecomputesNextRunButOtherChangesKeepIt(): void
    {
        $id = $this->jobIds['JA'];
        $this->db()->execute("UPDATE jobs SET next_run_at = '2026-10-07T08:05:00+00:00' WHERE id = :id", ['id' => $id]);

        $this->api('admin', 'PUT', '/api/jobs/' . $id, ['name' => 'Nur Name']);
        self::assertSame('2026-10-07T08:05:00+00:00', $this->rawJob($id)['next_run_at'], 'ohne Zeitplanänderung bleibt der Termin');

        $this->api('admin', 'PUT', '/api/jobs/' . $id, ['cron' => '0 12 * * *', 'timezone' => 'UTC']);
        self::assertSame('2026-10-07T12:00:00+00:00', $this->rawJob($id)['next_run_at']);

        $this->api('admin', 'PUT', '/api/jobs/' . $id, ['is_enabled' => false]);
        self::assertNull($this->rawJob($id)['next_run_at'], 'deaktiviert: kein Termin');

        $this->api('admin', 'PUT', '/api/jobs/' . $id, ['is_enabled' => true]);
        self::assertSame('2026-10-07T12:00:00+00:00', $this->rawJob($id)['next_run_at']);
    }

    public function testUpdateAuditListsOnlyFieldNames(): void
    {
        $this->api('admin', 'PUT', '/api/jobs/' . $this->jobIds['JA'], ['cron' => '0 1 * * *', 'http' => ['method' => 'HEAD', 'timeout_seconds' => 5], 'request' => ['url' => 'https://z.example.org/' . self::testSecret('aud')]]);

        $target = self::dig($this->db()->fetchOne("SELECT target FROM audit_log WHERE action = 'job.updated'"), 'target');

        self::assertSame('job:' . $this->jobIds['JA'] . ' JA; geändert: cron, http.method, http.timeout_seconds, Anfrage ersetzt', $target);
    }

    public function testNothingChangedWritesNoAuditEntry(): void
    {
        $this->api('admin', 'PUT', '/api/jobs/' . $this->jobIds['JA'], ['name' => 'JA']);

        self::assertNotContains('job.updated', $this->auditActions());
    }

    public function testShellJobsNeedTheShellRightAndAreNotEditableHere(): void
    {
        $this->db()->execute("INSERT INTO jobs (name, type, category_id, cron, timezone, config_json, payload_enc, created_at, updated_at) VALUES ('Shell', 'shell', 1, '* * * * *', 'UTC', '{}', 'v1:x', '2026-10-07T00:00:00+00:00', '2026-10-07T00:00:00+00:00')");
        $id = $this->db()->lastInsertId();

        self::assertSame(403, $this->api('operator', 'PUT', '/api/jobs/' . $id, ['name' => 'x'])->getStatusCode(), 'jobs.edit_shell fehlt');
        self::assertSame(403, $this->api('operator', 'DELETE', '/api/jobs/' . $id)->getStatusCode());
        self::assertSame(403, $this->api('operator', 'POST', '/api/jobs/' . $id . '/disable')->getStatusCode());
        self::assertSame(422, $this->api('admin', 'PUT', '/api/jobs/' . $id, ['name' => 'x'])->getStatusCode(), 'Shell-Jobs folgen mit Phase 4');
        self::assertSame(204, $this->api('admin', 'DELETE', '/api/jobs/' . $id)->getStatusCode());
    }

    // --- Löschen, Aktivieren, Deaktivieren -----------------------------------------------------------------

    /**
     * @return array<string, array{?string, string, int}>
     */
    public static function mutationMatrix(): array
    {
        return [
            'anonym' => [null, 'JA', 401],
            'Admin' => ['admin', 'JA', 200],
            'Operator B' => ['operator', 'JB', 200],
            'Operator nur A auf A' => ['operatorA', 'JA', 200],
            'Operator nur A auf B' => ['operatorA', 'JB', 404],
            'Operator nur A auf ohne' => ['operatorA', 'JN', 404],
            'Beobachter' => ['observer', 'JA', 403],
            'Beobachter nur A' => ['observerA', 'JA', 403],
        ];
    }

    #[DataProvider('mutationMatrix')]
    public function testDeleteMatrix(?string $who, string $job, int $status): void
    {
        $id = $this->jobIds[$job];
        $this->db()->execute("INSERT INTO runs (job_id, trigger, status) VALUES (:j, 'schedule', 'ok')", ['j' => $id]);

        $response = $this->api($who, 'DELETE', '/api/jobs/' . $id);

        $deleted = $status === 200;
        self::assertSame($deleted ? 204 : $status, $response->getStatusCode());
        self::assertSame($deleted ? 2 : 3, $this->jobCount());
        $runs = self::dig($this->db()->fetchOne('SELECT COUNT(*) AS n FROM runs WHERE job_id = :j', ['j' => $id]), 'n');
        self::assertSame($deleted ? 0 : 1, $runs, 'der Verlauf geht mit dem Job (CASCADE)');
        if ($deleted) {
            self::assertSame('', (string) $response->getContent());
            self::assertSame(['job.deleted'], $this->auditActions());
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    #[DataProvider('mutationMatrix')]
    public function testDisableAndEnableMatrix(?string $who, string $job, int $status): void
    {
        $id = $this->jobIds[$job];
        $this->db()->execute("UPDATE jobs SET next_run_at = '2026-10-07T08:05:00+00:00' WHERE id = :id", ['id' => $id]);

        $disable = $this->api($who, 'POST', '/api/jobs/' . $id . '/disable');

        self::assertSame($status, $disable->getStatusCode());
        if ($status !== 200) {
            self::assertSame(1, $this->rawJob($id)['is_enabled']);
            self::assertSame('2026-10-07T08:05:00+00:00', $this->rawJob($id)['next_run_at']);
            self::assertSame(
                $status,
                $this->api($who, 'POST', '/api/jobs/' . $id . '/enable')->getStatusCode(),
            );

            return;
        }
        self::assertSame(0, $this->rawJob($id)['is_enabled']);
        self::assertNull($this->rawJob($id)['next_run_at']);
        self::assertFalse($this->job($disable)['is_enabled']);

        $enable = $this->api($who, 'POST', '/api/jobs/' . $id . '/enable');
        self::assertSame(200, $enable->getStatusCode());
        self::assertSame(1, $this->rawJob($id)['is_enabled']);
        self::assertSame('2026-10-07T08:05:00+00:00', $this->rawJob($id)['next_run_at'], 'H6: nach dem Aktivieren neu berechnet (nächster 5-Minuten-Termin)');
        self::assertSame(['job.disabled', 'job.enabled'], $this->auditActions());
    }

    // --- CSRF -------------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function mutatingRequests(): array
    {
        return [
            'anlegen' => ['POST', '/api/jobs'],
            'ändern' => ['PUT', '/api/jobs/{id}'],
            'löschen' => ['DELETE', '/api/jobs/{id}'],
            'aktivieren' => ['POST', '/api/jobs/{id}/enable'],
            'deaktivieren' => ['POST', '/api/jobs/{id}/disable'],
        ];
    }

    #[DataProvider('mutatingRequests')]
    public function testMissingWrongOrForeignCsrfChangesNothing(string $method, string $path): void
    {
        $id = $this->jobIds['JA'];
        $path = str_replace('{id}', (string) $id, $path);
        $session = $this->as('admin');
        $content = json_encode($this->body(['name' => 'Eingeschleust']), JSON_THROW_ON_ERROR);
        $snapshot = $this->db()->fetchAll('SELECT * FROM jobs ORDER BY id');
        $variants = [
            'ohne Token' => ['Origin' => self::ORIGIN],
            'falsches Token' => ['Origin' => self::ORIGIN, 'X-CSRF-Token' => 'falsch'],
            'Token einer anderen Sitzung' => ['Origin' => self::ORIGIN, 'X-CSRF-Token' => $this->csrfOfOther()],
            'fremder Origin' => ['Origin' => 'https://evil.example', 'X-CSRF-Token' => $session['csrf']],
            'ohne Origin' => ['X-CSRF-Token' => $session['csrf']],
            'Referer fremd' => ['Referer' => 'https://evil.example/x', 'X-CSRF-Token' => $session['csrf']],
        ];

        foreach ($variants as $label => $headers) {
            $response = $this->rawApi('admin', $method, $path, $content, $headers);

            self::assertSame(403, $response->getStatusCode(), $label);
            self::assertSame('{"error":"CSRF-Prüfung fehlgeschlagen.","csrf_failed":true}', json_encode(json_decode((string) $response->getContent(), true), JSON_UNESCAPED_UNICODE), $label);
            self::assertSame($snapshot, $this->db()->fetchAll('SELECT * FROM jobs ORDER BY id'), 'unverändert: ' . $label);
            self::assertSame([], $this->auditActions(), $label);
        }
    }

    private function csrfOfOther(): string
    {
        return $this->as('observer')['csrf'];
    }

    public function testGetNeverChangesAnything(): void
    {
        $snapshot = $this->db()->fetchAll('SELECT * FROM jobs ORDER BY id');
        foreach (['/api/jobs/' . $this->jobIds['JA'] . '/enable', '/api/jobs/' . $this->jobIds['JA'] . '/disable'] as $path) {
            self::assertSame(405, $this->api('admin', 'GET', $path)->getStatusCode(), $path);
        }
        self::assertSame(405, $this->api('admin', 'PATCH', '/api/jobs/' . $this->jobIds['JA'])->getStatusCode());
        self::assertSame($snapshot, $this->db()->fetchAll('SELECT * FROM jobs ORDER BY id'));
    }

    public function testUnknownIdsAre404ForEveryWrite(): void
    {
        foreach (['PUT' => '/api/jobs/9999', 'DELETE' => '/api/jobs/9999'] as $method => $path) {
            self::assertSame(404, $this->api('admin', $method, $path, ['name' => 'x'])->getStatusCode(), $method);
        }
        self::assertSame(404, $this->api('admin', 'POST', '/api/jobs/9999/enable')->getStatusCode());
        self::assertSame(404, $this->api('admin', 'POST', '/api/jobs/0/disable')->getStatusCode());
    }

    // --- Validierung ---------------------------------------------------------------------------------------

    public function testTimeoutAboveTheGlobalMaximumIsRejected(): void
    {
        (new Settings($this->db(), $this->clock()))->set(SettingKey::HttpMaxTimeout, 60, 1);

        $response = $this->api('admin', 'POST', '/api/jobs', $this->body(['http' => ['timeout_seconds' => 61]]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('höchstens 60 s', self::textOf(self::dig($this->json($response), 'fields', 'http.timeout_seconds')));
        self::assertSame(201, $this->api('admin', 'POST', '/api/jobs', $this->body(['http' => ['timeout_seconds' => 60]]))->getStatusCode());
    }

    public function testStoredTimeoutAboveALoweredMaximumMustBeFixedOnSave(): void
    {
        $id = self::intOf($this->job($this->api('admin', 'POST', '/api/jobs', $this->body(['http' => ['timeout_seconds' => 200]])))['id']);
        (new Settings($this->db(), $this->clock()))->set(SettingKey::HttpMaxTimeout, 100, 1);

        self::assertSame(422, $this->api('admin', 'PUT', '/api/jobs/' . $id, ['name' => 'x'])->getStatusCode());
        self::assertSame(200, $this->api('admin', 'PUT', '/api/jobs/' . $id, ['http' => ['timeout_seconds' => 100]])->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenBodies(): array
    {
        return [
            'kein JSON' => ['{nope'],
            'Liste' => ['[1,2]'],
            'Text' => ['"x"'],
            'zu tief' => ['{"a":{"b":{"c":{"d":{"e":{"f":1}}}}}}'],
            'zu groß' => ['GROSS'],
        ];
    }

    #[DataProvider('brokenBodies')]
    public function testBrokenBodiesAre422(string $content): void
    {
        if ($content === 'GROSS') {
            $content = '{"name":"' . str_repeat('a', 170000) . '"}';
        }
        $before = $this->jobCount();

        $create = $this->rawApi('admin', 'POST', '/api/jobs', $content);
        $update = $this->rawApi('admin', 'PUT', '/api/jobs/' . $this->jobIds['JA'], $content);

        self::assertSame(422, $create->getStatusCode());
        self::assertSame(422, $update->getStatusCode());
        self::assertArrayHasKey('body', (array) $this->json($create)['fields']);
        self::assertSame($before, $this->jobCount());
    }

    public function testRequestBodyMayNestUpToFiveLevels(): void
    {
        $response = $this->api('admin', 'POST', '/api/jobs', $this->body(['request' => ['url' => 'https://a.example.org/', 'headers' => [['name' => 'X-A', 'value' => 'b']]]]));

        self::assertSame(201, $response->getStatusCode());
    }

    public function testUnknownFieldsAndIncompleteShellAreRejectedWithoutChange(): void
    {
        $before = $this->jobCount();
        $owner = $this->api('admin', 'POST', '/api/jobs', $this->body(['owner_id' => 99]));
        $shell = $this->api('admin', 'POST', '/api/jobs', $this->body(['type' => 'shell']));
        $id = $this->api('admin', 'POST', '/api/jobs', $this->body(['id' => 5]));

        self::assertSame(422, $owner->getStatusCode());
        self::assertSame(422, $shell->getStatusCode());
        self::assertIsString(self::dig($this->json($shell), 'fields', 'body'));
        self::assertSame(422, $id->getStatusCode());
        self::assertSame($before, $this->jobCount());
    }

    public function testInvalidCronCreatesNothing(): void
    {
        $before = $this->jobCount();

        $response = $this->api('admin', 'POST', '/api/jobs', $this->body(['cron' => '99 * * * *']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($before, $this->jobCount());
        self::assertSame([], $this->auditActions());
    }

    public function testLeavingOutRequiredFieldsOnCreateIsRejected(): void
    {
        $errors = (array) $this->json($this->api('admin', 'POST', '/api/jobs', ['type' => 'http']))['fields'];

        self::assertArrayHasKey('name', $errors);
        self::assertArrayHasKey('cron', $errors);
        self::assertArrayHasKey('request', $errors);
    }

    // --- Leaks -------------------------------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function secrets(): array
    {
        return [self::testSecret('path'), self::testSecret('query'), self::testSecret('header'), self::testSecret('body')];
    }

    public function testASecretInPathQueryHeaderOrBodyAppearsNowhereExceptEncrypted(): void
    {
        [$path, $query, $header, $body] = $this->secrets();
        $create = $this->api('operatorA', 'POST', '/api/jobs', $this->body([
            'name' => 'Leaktest',
            'http' => ['method' => 'POST'],
            'request' => [
                'url' => 'https://api.example.org/hooks/' . $path . '/run?key=' . $query . '&mode=x',
                'headers' => [['name' => 'Authorization', 'value' => 'Bearer ' . $header]],
                'body' => '{"token":"' . $body . '"}',
            ],
        ]));
        $id = self::intOf($this->job($create)['id']);
        $this->api('operatorA', 'PUT', '/api/jobs/' . $id, ['name' => 'Leaktest 2', 'cron' => '0 5 * * *']);
        $this->api('operatorA', 'POST', '/api/jobs/' . $id . '/disable');
        $this->api('operatorA', 'POST', '/api/jobs/' . $id . '/enable');

        $responses = [
            'POST' => $this->plain($create),
            'Liste' => $this->plain($this->api('operatorA', 'GET', '/api/jobs')),
            'Detail' => $this->plain($this->api('operatorA', 'GET', '/api/jobs/' . $id)),
            'Verlauf' => $this->plain($this->api('operatorA', 'GET', '/api/jobs/' . $id . '/runs')),
            'Audit' => $this->plain($this->api('admin', 'GET', '/api/audit')),
        ];
        foreach ($responses as $where => $text) {
            self::assertNoSecret($text, $this->secrets(), $where);
        }
        self::assertSame('https://api.example.org/hooks/••••/run?key=••••&mode=••••', self::dig($this->job($create), 'http', 'display_url'));

        $row = $this->rawJob($id);
        foreach (['name', 'config_json', 'cron', 'timezone'] as $column) {
            self::assertNoSecret(self::textOf($row[$column]), $this->secrets(), 'Spalte ' . $column);
        }
        self::assertNoSecret(self::textOf($row['payload_enc']), $this->secrets(), 'payload_enc verschlüsselt');
        foreach ($this->db()->fetchAll('SELECT action, target FROM audit_log') as $entry) {
            self::assertNoSecret(json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->secrets(), 'Audit-Log');
        }
        // Kein Header-Name in der Antwort (O5): nur Anzahl und Flag.
        self::assertStringNotContainsString('Authorization', $responses['Detail']);
    }

    public function testValidationErrorsNeverEchoTheInput(): void
    {
        [$path, $query, $header, $body] = $this->secrets();
        $attempts = [
            ['request' => ['url' => 'ftp://x.example.org/' . $path . '?k=' . $query]],
            ['request' => ['url' => 'https://u:' . $path . '@x.example.org/']],
            ['request' => ['url' => 'https://x.example.org/' . $path . '#' . $query]],
            ['request' => ['url' => 'https://x.example.org/' . $path, 'headers' => [['name' => 'Host', 'value' => $header]]]],
            ['request' => ['url' => 'https://x.example.org/' . $path, 'headers' => [['name' => 'X-A', 'value' => $header . "\r\nX-B: 1"]]]],
            ['request' => ['url' => 'https://x.example.org/' . $path, 'headers' => [['name' => 'X-A B', 'value' => $header]]]],
            ['request' => ['url' => 'https://x.example.org/' . $path, 'body' => $body]],
            ['request' => ['url' => 'http://127.0.0.1/' . $path . '?k=' . $query]],
            ['request' => ['url' => 'http://localhost/' . $path]],
            ['request' => ['url' => 'https://x.example.org/' . $path, 'headers' => 'Authorization: ' . $header]],
            ['name' => $header . "\n", 'request' => ['url' => 'https://x.example.org/' . $path]],
            ['cron' => $query, 'request' => ['url' => 'https://x.example.org/' . $path, 'body' => $body]],
            ['request' => ['url' => 'https://x.example.org/' . $path, 'extra' => $body]],
        ];

        foreach ($attempts as $i => $attempt) {
            $response = $this->api('admin', 'POST', '/api/jobs', $this->body($attempt));
            self::assertSame(422, $response->getStatusCode(), 'Fall ' . $i);
            self::assertNoSecret($this->plain($response), $this->secrets(), 'Fehlerantwort ' . $i);
        }
        self::assertSame([], $this->auditActions());
        self::assertNoSecret((string) json_encode($this->db()->fetchAll('SELECT * FROM jobs')), $this->secrets(), 'keine Zeile geschrieben');
    }

    public function testASecretInTheJobNameIsMaskedInEveryOutput(): void
    {
        // Presenter und Audit-Log maskieren zusätzlich: ein Geheimnis im Namen (bekanntes Muster) verlässt das System nicht.
        $secret = self::testSecret('name');
        $created = $this->api('admin', 'POST', '/api/jobs', $this->body(['name' => 'token=' . $secret]));

        self::assertSame(201, $created->getStatusCode());
        self::assertNoSecret($this->plain($created), [$secret], 'Antwort');
        self::assertNoSecret($this->plain($this->api('admin', 'GET', '/api/jobs')), [$secret], 'Liste');
        self::assertNoSecret($this->plain($this->api('admin', 'GET', '/api/audit')), [$secret], 'Audit-Antwort');
        $target = self::textOf(self::dig($this->db()->fetchOne("SELECT target FROM audit_log WHERE action = 'job.created'"), 'target'));
        self::assertNoSecret($target, [$secret], 'Audit-Eintrag (maskiert beim Schreiben)');
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
