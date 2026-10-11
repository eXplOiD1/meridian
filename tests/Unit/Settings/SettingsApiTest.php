<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Settings;

use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Job\HttpJobConfig;
use Meridian\Security\CategoryScope;
use Meridian\Tests\Support\JobApiHarness;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Einstellungs-API (docs/decisions/0003, E10, E3, §4.2, §4.3): nur `settings.manage` uneingeschränkt, CSRF,
 * Allowlist der Schlüssel, strenge Typen, Audit mit altem und neuem Wert, Roundtrip über eine neue Verbindung,
 * Verschärfen der gespeicherten Anzeige-URLs beim Wechsel auf `hidden` (und kein Lockern zurück), alles in einer
 * Transaktion, keine Geheimnisse in Antworten und Audit.
 */
final class SettingsApiTest extends TestCase
{
    use JobApiHarness;

    private const PATH = '/api/settings';
    private const TIMEOUT = '/api/settings/http.max_timeout_seconds';
    private const DISPLAY = '/api/settings/http.display_path';

    private ?string $dbFile = null;

    /** Datei statt In-Memory, damit eine zweite Verbindung denselben Stand sieht (Roundtrip). */
    private function db(): Connection
    {
        if ($this->dbInstance === null) {
            $this->dbFile = SchedulerHarness::tempDatabase();
            $this->dbInstance = Connection::open($this->dbFile);
            (new Migrator($this->dbInstance, dirname(__DIR__, 3) . '/migrations'))->migrate();
        }

        return $this->dbInstance;
    }

    private function freshConnection(): Connection
    {
        $this->db();
        self::assertNotNull($this->dbFile);

        return Connection::open($this->dbFile);
    }

    protected function tearDown(): void
    {
        $this->kernelInstance = null;
        $this->dbInstance = null;
        if ($this->dbFile !== null) {
            SchedulerHarness::removeDatabase($this->dbFile);
        }
    }

    private function settingRows(): int
    {
        $n = self::dig($this->freshConnection()->fetchOne('SELECT COUNT(*) AS n FROM settings'), 'n');
        self::assertIsInt($n);

        return $n;
    }

    /**
     * @return list<array{target: string, user_id: int|null}>
     */
    private function auditEntries(): array
    {
        $out = [];
        foreach ($this->freshConnection()->fetchAll("SELECT target, user_id FROM audit_log WHERE action = 'settings.changed' ORDER BY id") as $row) {
            $out[] = ['target' => is_string($row['target']) ? $row['target'] : '', 'user_id' => is_int($row['user_id']) ? $row['user_id'] : null];
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function settingsOf(Response $response): array
    {
        $list = $this->json($response)['settings'] ?? null;
        self::assertIsArray($list);
        $out = [];
        foreach ($list as $entry) {
            self::assertIsArray($entry);
            $key = $entry['key'] ?? null;
            self::assertIsString($key);
            /** @var array<string, mixed> $entry */
            $out[$key] = $entry;
        }

        return $out;
    }

    /**
     * Legt HTTP-Jobs direkt über Validator und Repository an (Anzeige-URL im Modus der aktuellen Einstellung).
     *
     * @param array<string, string> $urls Name => URL
     *
     * @return array<string, int> Name => ID
     */
    private function seedHttpJobs(array $urls): array
    {
        $this->as('admin');
        $ids = [];
        foreach ($urls as $name => $url) {
            $draft = $this->validator()->validate(self::jobInput(['name' => $name, 'request' => ['url' => $url]]), CategoryScope::everything(), null);
            $ids[$name] = $this->jobRepository()->insert($draft, 1);
        }

        return $ids;
    }

    private function storedDisplayUrl(int $jobId): string
    {
        $json = self::dig($this->freshConnection()->fetchOne('SELECT config_json FROM jobs WHERE id = :id', ['id' => $jobId]), 'config_json');
        self::assertIsString($json);

        return HttpJobConfig::fromJson($json)->displayUrl;
    }

    private function apiDisplayUrl(int $jobId): mixed
    {
        return self::dig($this->json($this->api('admin', 'GET', '/api/jobs/' . $jobId)), 'job', 'http', 'display_url');
    }

    // ---- Rechte ----

    public function testAnonymousGets401AndNothingChanges(): void
    {
        self::assertSame(401, $this->api(null, 'GET', self::PATH)->getStatusCode());
        $put = $this->call('PUT', self::TIMEOUT, ['value' => 600], ['Origin' => self::ORIGIN]);
        self::assertSame(401, $put->getStatusCode());
        self::assertNull(self::dig($this->json($put), 'setting'));
        self::assertSame(0, $this->settingRows());
        self::assertSame([], $this->auditEntries());
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function roles(): array
    {
        return [
            'Admin' => ['admin', true],
            'Admin auf Kategorie A beschränkt' => ['adminA', false],
            'Operator' => ['operator', false],
            'Operator auf A' => ['operatorA', false],
            'Beobachter' => ['observer', false],
            'Beobachter auf A' => ['observerA', false],
        ];
    }

    #[DataProvider('roles')]
    public function testOnlyUnrestrictedHoldersOfSettingsManage(string $who, bool $allowed): void
    {
        $this->seedCategories();
        $list = $this->api($who, 'GET', self::PATH);
        $put = $this->api($who, 'PUT', self::TIMEOUT, ['value' => 600]);
        $reset = $this->api($who, 'PUT', self::DISPLAY, ['value' => null]);

        if ($allowed) {
            self::assertSame([200, 200, 200], [$list->getStatusCode(), $put->getStatusCode(), $reset->getStatusCode()]);
            self::assertSame(1, $this->settingRows());

            return;
        }
        self::assertSame([403, 403, 403], [$list->getStatusCode(), $put->getStatusCode(), $reset->getStatusCode()]);
        self::assertNull(self::dig($this->json($list), 'settings'), 'keine Daten bei verweigertem Zugriff');
        self::assertStringNotContainsString('http.', (string) $list->getContent());
        self::assertSame(0, $this->settingRows(), 'nichts geändert');
        self::assertSame([], $this->auditEntries(), 'abgelehnte Anfragen werden nicht protokolliert');
    }

    public function testDeniedEvenForUnknownKeysBeforeTheAllowlistIsConsulted(): void
    {
        // Ohne Recht 403, nicht 404: die Prüfung des Schlüssels kommt erst nach dem Recht.
        self::assertSame(403, $this->api('operator', 'PUT', '/api/settings/http.nope', ['value' => 1])->getStatusCode());
    }

    // ---- CSRF und Herkunft ----

    public function testPutWithoutCsrfTokenIsRejectedAndChangesNothing(): void
    {
        $response = $this->api('admin', 'PUT', self::TIMEOUT, ['value' => 600], csrf: false);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->settingRows());
        self::assertSame([], $this->auditEntries());
    }

    public function testPutWithForeignOriginOrWrongTokenIsRejected(): void
    {
        $session = $this->as('admin');
        $cookies = [self::COOKIE => $session['cookie']];

        $foreign = $this->call('PUT', self::TIMEOUT, ['value' => 600], ['Origin' => 'https://evil.test', 'X-CSRF-Token' => $session['csrf']], $cookies);
        $wrong = $this->call('PUT', self::TIMEOUT, ['value' => 600], ['Origin' => self::ORIGIN, 'X-CSRF-Token' => str_repeat('0', 64)], $cookies);
        $noOrigin = $this->call('PUT', self::TIMEOUT, ['value' => 600], ['X-CSRF-Token' => $session['csrf']], $cookies);

        self::assertSame([403, 403, 403], [$foreign->getStatusCode(), $wrong->getStatusCode(), $noOrigin->getStatusCode()]);
        self::assertSame(0, $this->settingRows());
    }

    public function testGetDoesNotChangeAnything(): void
    {
        $this->api('admin', 'GET', self::PATH);
        $this->api('admin', 'GET', self::PATH);

        self::assertSame(0, $this->settingRows(), 'Lesen legt keine Zeile an');
        self::assertSame([], $this->auditEntries());
    }

    // ---- Lesen ----

    public function testListContainsEveryAllowedKeyWithValueDefaultAndSource(): void
    {
        $response = $this->api('admin', 'GET', self::PATH);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $settings = $this->settingsOf($response);
        self::assertSame(['http.max_timeout_seconds','http.response_storage','http.display_path','http.display_host','shell.max_timeout_seconds','jobs.reveal_for_edit','app.public_url','runs.retention_days','jobs.min_interval_seconds','auth.session_days'], array_keys($settings));
        self::assertSame(
            ['key' => 'http.max_timeout_seconds', 'value' => 300, 'default' => 300, 'source' => 'default', 'updated_at' => null, 'updated_by' => null],
            $settings['http.max_timeout_seconds'],
            'Standard-Maximum 300 s, ohne Zeile',
        );
        self::assertSame(['key' => 'http.response_storage', 'value' => 'off', 'default' => 'off', 'source' => 'default', 'updated_at' => null, 'updated_by' => null], $settings['http.response_storage']);
        self::assertSame(['key' => 'http.display_path', 'value' => 'hidden', 'default' => 'hidden', 'source' => 'default', 'updated_at' => null, 'updated_by' => null], $settings['http.display_path']);
    }

    public function testPutReturnsTheNewEntryAndTheListShowsWhoChangedIt(): void
    {
        $put = $this->api('admin', 'PUT', self::TIMEOUT, ['value' => 600]);

        self::assertSame(200, $put->getStatusCode());
        self::assertStringContainsString('no-store', (string) $put->headers->get('Cache-Control'));
        self::assertSame([
            'setting' => ['key' => 'http.max_timeout_seconds', 'value' => 600, 'default' => 300, 'source' => 'stored', 'updated_at' => '2026-10-07T08:00:00+00:00', 'updated_by' => ['display_name' => 'Admin']],
            'tightened_jobs' => 0,
        ], $this->json($put));
        self::assertSame(600, $this->settingsOf($this->api('admin', 'GET', self::PATH))['http.max_timeout_seconds']['value'] ?? null);
    }

    public function testInvalidStoredValueIsShownAsDefault(): void
    {
        $this->db()->execute("INSERT INTO settings (key, value_json, updated_by, updated_at) VALUES ('http.max_timeout_seconds', '99999', NULL, '2026-10-07T00:00:00+00:00')");

        $entry = $this->settingsOf($this->api('admin', 'GET', self::PATH))['http.max_timeout_seconds'];

        self::assertSame(300, $entry['value'] ?? null, 'nie lockerer als der Standard');
        self::assertSame('default', $entry['source'] ?? null);
    }

    // ---- Schreiben, Roundtrip, Audit ----

    public function testRoundtripThroughANewConnectionAndAuditWithOldAndNewValue(): void
    {
        self::assertSame(200, $this->api('admin', 'PUT', self::TIMEOUT, ['value' => 600])->getStatusCode());
        self::assertSame(200, $this->api('admin', 'PUT', '/api/settings/http.response_storage', ['value' => 'never'])->getStatusCode());
        self::assertSame(200, $this->api('admin', 'PUT', self::TIMEOUT, ['value' => 3600])->getStatusCode());

        $rows = $this->freshConnection()->fetchAll('SELECT key, value_json, updated_by FROM settings ORDER BY key');
        self::assertSame([
            ['key' => 'http.max_timeout_seconds', 'value_json' => '3600', 'updated_by' => 1],
            ['key' => 'http.response_storage', 'value_json' => '"never"', 'updated_by' => 1],
        ], $rows);
        self::assertSame([
            ['target' => 'http.max_timeout_seconds: 300 → 600', 'user_id' => 1],
            ['target' => 'http.response_storage: off → never', 'user_id' => 1],
            ['target' => 'http.max_timeout_seconds: 600 → 3600', 'user_id' => 1],
        ], $this->auditEntries());
    }

    public function testNullResetsToTheDefaultByDeletingTheRow(): void
    {
        $this->api('admin', 'PUT', self::TIMEOUT, ['value' => 600]);

        $reset = $this->api('admin', 'PUT', self::TIMEOUT, ['value' => null]);

        self::assertSame(200, $reset->getStatusCode());
        self::assertSame(['key' => 'http.max_timeout_seconds', 'value' => 300, 'default' => 300, 'source' => 'default', 'updated_at' => null, 'updated_by' => null], self::dig($this->json($reset), 'setting'));
        self::assertSame(0, $this->settingRows());
        self::assertSame('http.max_timeout_seconds: 600 → 300 (Standard)', $this->auditEntries()[1]['target'] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function validBoundaries(): array
    {
        return ['Minimum 1' => ['/api/settings/http.max_timeout_seconds', 1], 'Maximum 3600' => ['/api/settings/http.max_timeout_seconds', 3600]];
    }

    #[DataProvider('validBoundaries')]
    public function testTimeoutBoundariesAreAccepted(string $path, int $value): void
    {
        self::assertSame(200, $this->api('admin', 'PUT', $path, ['value' => $value])->getStatusCode());
        self::assertSame($value, self::dig($this->settingsOf($this->api('admin', 'GET', self::PATH)), 'http.max_timeout_seconds', 'value'));
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidValues(): array
    {
        return [
            'Zeitlimit 0' => ['http.max_timeout_seconds', 0],
            'Zeitlimit -1' => ['http.max_timeout_seconds', -1],
            'Zeitlimit 3601' => ['http.max_timeout_seconds', 3601],
            'Zeitlimit als Text' => ['http.max_timeout_seconds', '300'],
            'Zeitlimit Kommazahl' => ['http.max_timeout_seconds', 300.5],
            'Zeitlimit bool' => ['http.max_timeout_seconds', true],
            'Speichern maybe' => ['http.response_storage', 'maybe'],
            'Speichern groß' => ['http.response_storage', 'ON'],
            'Speichern Zahl' => ['http.response_storage', 1],
            'Pfad inherit' => ['http.display_path', 'inherit'],
            'Pfad leer' => ['http.display_path', ''],
            'Pfad Liste' => ['http.display_path', ['hidden']],
            'Pfad Objekt' => ['http.display_path', ['mode' => 'hidden']],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testWrongTypeOrRangeIs422WithoutChange(string $key, mixed $value): void
    {
        $response = $this->api('admin', 'PUT', '/api/settings/' . $key, ['value' => $value]);

        self::assertSame(422, $response->getStatusCode());
        self::assertIsString(self::dig($this->json($response), 'fields', is_array($value) ? 'body' : 'value'));
        self::assertSame(0, $this->settingRows());
        self::assertSame([], $this->auditEntries());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badBodies(): array
    {
        return [
            'leer' => [''],
            'kein JSON' => ['value=600'],
            'ohne value' => ['{}'],
            'Liste' => ['[600]'],
            'Fremdfeld' => ['{"value": 600, "key": "http.display_path"}'],
            'zu groß' => ['{"value": "' . str_repeat('a', 2000) . '"}'],
        ];
    }

    #[DataProvider('badBodies')]
    public function testMalformedBodyIs422(string $content): void
    {
        $response = $this->rawApi('admin', 'PUT', self::TIMEOUT, $content);

        self::assertSame(422, $response->getStatusCode());
        self::assertIsString(self::dig($this->json($response), 'fields', 'body'));
        self::assertSame(0, $this->settingRows());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownKeys(): array
    {
        return [
            'unbekannt' => ['http.nope'],
            'Recht als Schlüssel' => ['settings.manage'],
            'Präfix' => ['http.max_timeout'],
            'Großbuchstaben' => ['HTTP.DISPLAY_PATH'],
            'Pfad' => ['http.display_path/x'],
        ];
    }

    #[DataProvider('unknownKeys')]
    public function testUnknownKeyIs404AndNothingIsWritten(string $key): void
    {
        $response = $this->api('admin', 'PUT', '/api/settings/' . $key, ['value' => 'hidden']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->settingRows());
        self::assertSame([], $this->auditEntries());
    }

    // ---- Anzeige-URLs verschärfen ----

    public function testSwitchingToHiddenTightensStoredDisplayUrlsAndBackToAutoLoosensNothing(): void
    {
        (new \Meridian\Settings\Settings($this->db(), $this->clock()))->set(\Meridian\Settings\SettingKey::HttpDisplayPath, 'auto', null);
        $ids = $this->seedHttpJobs([
            'Pfad und Query' => 'https://img.example.org/tools/api/cron.php?key=' . self::testSecret('q') . '&mode=x',
            'Kleinbuchstaben-Token' => 'https://hooks.example.org/hooks/abcdefghqrst',
            'Nur Query' => 'http://example.org/?a=1',
            'Wurzel mit Port' => 'https://example.org:8443',
        ]);
        self::assertSame('https://hooks.example.org/hooks/abcdefghqrst', $this->storedDisplayUrl($ids['Kleinbuchstaben-Token']), 'Ausgangslage auto');
        $before = $this->freshConnection()->fetchOne('SELECT config_json, updated_at FROM jobs WHERE id = :id', ['id' => $ids['Pfad und Query']]);

        $hidden = $this->api('admin', 'PUT', self::DISPLAY, ['value' => 'hidden']);

        self::assertSame(200, $hidden->getStatusCode());
        self::assertSame(3, self::dig($this->json($hidden), 'tightened_jobs'));
        $expected = [
            'Pfad und Query' => 'https://img.example.org/••••?••••',
            'Kleinbuchstaben-Token' => 'https://hooks.example.org/••••',
            'Nur Query' => 'http://example.org/••••?••••',
            'Wurzel mit Port' => 'https://example.org:8443',
        ];
        foreach ($expected as $name => $url) {
            self::assertSame($url, $this->storedDisplayUrl($ids[$name]), $name . ' (neue Verbindung)');
            self::assertSame($url, $this->apiDisplayUrl($ids[$name]), $name . ' (API)');
        }
        $all = (string) json_encode($this->freshConnection()->fetchAll('SELECT config_json FROM jobs'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['tools', 'cron.php', 'key=', 'mode', 'hooks/', 'abcdefghqrst', 'a='] as $fragment) {
            self::assertStringNotContainsString($fragment, $all, 'kein Pfad und kein Query-Name mehr');
        }
        // Sonst bleibt alles gleich: nur display_url geändert, updated_at unverändert.
        $after = $this->freshConnection()->fetchOne('SELECT config_json, updated_at FROM jobs WHERE id = :id', ['id' => $ids['Pfad und Query']]);
        self::assertIsString($before['config_json'] ?? null);
        self::assertIsString($after['config_json'] ?? null);
        self::assertSame($before['updated_at'] ?? null, $after['updated_at'] ?? null);
        self::assertEquals(
            HttpJobConfig::fromJson($before['config_json'])->withDisplayUrl('https://img.example.org/••••?••••'),
            HttpJobConfig::fromJson($after['config_json']),
        );
        self::assertSame('http.display_path: auto → hidden; Anzeige-URL bei 3 Job(s) verschärft', $this->auditEntries()[0]['target'] ?? null);

        $auto = $this->api('admin', 'PUT', self::DISPLAY, ['value' => 'auto']);

        self::assertSame(200, $auto->getStatusCode());
        self::assertSame(0, self::dig($this->json($auto), 'tightened_jobs'));
        foreach ($expected as $name => $url) {
            self::assertSame($url, $this->storedDisplayUrl($ids[$name]), $name . ': zurück auf auto lockert nichts');
        }
        self::assertSame('http.display_path: hidden → auto', $this->auditEntries()[1]['target'] ?? null);

        // Zurücksetzen auf den Standard (auto) lockert ebenfalls nichts.
        $this->api('admin', 'PUT', self::DISPLAY, ['value' => 'hidden']);
        $this->api('admin', 'PUT', self::DISPLAY, ['value' => null]);
        self::assertSame('https://hooks.example.org/••••', $this->storedDisplayUrl($ids['Kleinbuchstaben-Token']));
    }

    public function testOtherSettingsAndRepeatedHiddenDoNotTouchJobs(): void
    {
        (new \Meridian\Settings\Settings($this->db(), $this->clock()))->set(\Meridian\Settings\SettingKey::HttpDisplayPath, 'auto', null);
        $ids = $this->seedHttpJobs(['Job' => 'https://img.example.org/tools/api/cron.php']);

        $this->api('admin', 'PUT', self::TIMEOUT, ['value' => 600]);
        $this->api('admin', 'PUT', '/api/settings/http.response_storage', ['value' => 'on']);
        self::assertSame('https://img.example.org/tools/api/cron.php', $this->storedDisplayUrl($ids['Job']), 'andere Schlüssel verschärfen nichts');

        self::assertSame(1, self::dig($this->json($this->api('admin', 'PUT', self::DISPLAY, ['value' => 'hidden'])), 'tightened_jobs'));
        self::assertSame(0, self::dig($this->json($this->api('admin', 'PUT', self::DISPLAY, ['value' => 'hidden'])), 'tightened_jobs'), 'zweimal hidden ändert nichts mehr');
    }

    public function testUnreadableConfigIsLeftAloneAndDoesNotBlockTheChange(): void
    {
        (new \Meridian\Settings\Settings($this->db(), $this->clock()))->set(\Meridian\Settings\SettingKey::HttpDisplayPath, 'auto', null);
        $ids = $this->seedHttpJobs(['Gut' => 'https://img.example.org/tools/api/cron.php', 'Kaputt' => 'https://img.example.org/tools/x.php']);
        $this->db()->execute("UPDATE jobs SET config_json = '{\"v\":99}' WHERE id = :id", ['id' => $ids['Kaputt']]);

        $response = $this->api('admin', 'PUT', self::DISPLAY, ['value' => 'hidden']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, self::dig($this->json($response), 'tightened_jobs'));
        self::assertSame('{"v":99}', self::dig($this->freshConnection()->fetchOne('SELECT config_json FROM jobs WHERE id = :id', ['id' => $ids['Kaputt']]), 'config_json'));
        self::assertSame('https://img.example.org/••••', $this->storedDisplayUrl($ids['Gut']));
    }

    public function testSettingAndTighteningAreOneTransaction(): void
    {
        (new \Meridian\Settings\Settings($this->db(), $this->clock()))->set(\Meridian\Settings\SettingKey::HttpDisplayPath, 'auto', null);
        $ids = $this->seedHttpJobs(['Job' => 'https://img.example.org/tools/api/cron.php']);
        // Audit schlägt fehl → alles muss zurückgerollt werden (Einstellung und verschärfte Anzeige-URL).
        $this->db()->execute("CREATE TRIGGER audit_fails BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT, 'Testfehler'); END");

        $previous = ini_set('error_log', '/dev/null');
        try {
            $response = $this->api('admin', 'PUT', self::DISPLAY, ['value' => 'hidden']);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(0, $this->settingRows(), 'Einstellung zurückgerollt');
        self::assertSame('https://img.example.org/tools/api/cron.php', $this->storedDisplayUrl($ids['Job']), 'Anzeige-URL zurückgerollt');
    }

    // ---- Leak ----

    public function testNoSecretsInResponsesOrAudit(): void
    {
        $secret = self::testSecret('leak');
        $this->seedHttpJobs(['Job' => 'https://img.example.org/tools/' . $secret . '/cron.php?key=' . $secret]);
        $this->db()->execute('UPDATE users SET display_name = :d WHERE username = :u', ['d' => 'Alex token=' . $secret, 'u' => 'admin']);

        $responses = [
            $this->api('admin', 'PUT', self::DISPLAY, ['value' => 'hidden']),
            $this->api('admin', 'PUT', self::TIMEOUT, ['value' => $secret]),
            $this->api('admin', 'PUT', self::TIMEOUT, ['value' => ['x' => $secret]]),
            $this->api('admin', 'PUT', '/api/settings/' . strtolower(substr($secret, 0, 10)), ['value' => $secret]),
            $this->api('admin', 'GET', self::PATH),
            $this->api('operator', 'PUT', self::TIMEOUT, ['value' => $secret]),
        ];

        foreach ($responses as $i => $response) {
            self::assertStringNotContainsString($secret, (string) $response->getContent(), 'Antwort ' . $i);
            self::assertStringNotContainsString(rawurlencode($secret), (string) $response->getContent(), 'Antwort ' . $i);
        }
        $updatedBy = self::dig($this->settingsOf($responses[4]), 'http.display_path', 'updated_by', 'display_name');
        self::assertSame('Alex token=••••', $updatedBy, 'Anzeigename läuft durch den Masker');
        $audit = (string) json_encode($this->freshConnection()->fetchAll('SELECT action, target FROM audit_log'), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString($secret, $audit);
        $settings = (string) json_encode($this->freshConnection()->fetchAll('SELECT * FROM settings'), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString($secret, $settings);
    }
}
