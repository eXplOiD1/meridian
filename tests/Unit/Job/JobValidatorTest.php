<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Job;

use Meridian\Http\ValidationFailed;
use Meridian\Job\HttpMethod;
use Meridian\Job\StoreResponse;
use Meridian\Runner\JobType;
use Meridian\Schedule\OverlapPolicy;
use Meridian\Security\CategoryScope;
use Meridian\Settings\SettingKey;
use Meridian\Tests\Support\JobHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validierung nach docs/decisions/0003, §3.2: Allowlist, ablehnen statt zurechtschneiden, Meldungen ohne
 * Eingabewert.
 */
final class JobValidatorTest extends TestCase
{
    use JobHarness;

    protected function setUp(): void
    {
        $this->setUpJobDatabase();
        $this->settings()->set(\Meridian\Settings\SettingKey::HttpDisplayPath, 'auto', 1);
    }

    protected function tearDown(): void
    {
        $this->tearDownJobDatabase();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private function errorsOf(array $input, ?CategoryScope $scope = null): array
    {
        try {
            $this->validator()->validate($input, $scope ?? CategoryScope::everything(), null);
        } catch (ValidationFailed $e) {
            return $e->fields();
        }
        self::fail('Eingabe wurde nicht abgelehnt.');
    }

    public function testMinimalInputGetsTheDocumentedDefaults(): void
    {
        $draft = $this->draft(['name' => 'Hub', 'type' => 'http', 'cron' => ' *   * * * * ', 'category_id' => null, 'request' => ['url' => 'https://example.org/']]);

        self::assertSame('Hub', $draft->name);
        self::assertSame(JobType::Http, $draft->type);
        self::assertNull($draft->categoryId);
        self::assertSame('* * * * *', $draft->cron, 'Leerraum im Cron wird vereinheitlicht');
        self::assertSame('Europe/Berlin', $draft->timezone);
        self::assertTrue($draft->isEnabled);
        self::assertFalse($draft->catchUp);
        self::assertSame(OverlapPolicy::Skip, $draft->overlapPolicy);
        self::assertSame(0, $draft->retryCount);
        self::assertSame(60, $draft->retryDelaySeconds);
        self::assertSame(HttpMethod::Get, $draft->http()->method);
        self::assertSame(30, $draft->http()->timeoutSeconds);
        self::assertSame('200-299', $draft->http()->expectedStatusText());
        self::assertSame(3, $draft->http()->maxRedirects);
        self::assertSame(StoreResponse::Inherit, $draft->http()->storeResponse);
        self::assertSame('https://example.org/', $draft->http()->displayUrl);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInputs(): array
    {
        $url = ['request' => ['url' => 'https://example.org/']];

        return [
            'Name fehlt' => [['name' => null] + $url, 'name'],
            'Name leer' => [['name' => ''] + $url, 'name'],
            'Name 101 Zeichen' => [['name' => str_repeat('ä', 101)] + $url, 'name'],
            'Name mit Steuerzeichen' => [['name' => "a\nb"] + $url, 'name'],
            'Name mit Leerraum am Rand' => [['name' => ' Hub'] + $url, 'name'],
            'Name keine Zeichenkette' => [['name' => 5] + $url, 'name'],
            'Art fehlt' => [['type' => null] + $url, 'type'],
            'Shell ohne Skript' => [['type' => 'shell'] + $url, 'body'],
            'Art unbekannt' => [['type' => 'ftp'] + $url, 'type'],
            'Kategorie unbekannt' => [['category_id' => 99] + $url, 'category_id'],
            'Kategorie als Text' => [['category_id' => '1'] + $url, 'category_id'],
            'Cron leer' => [['cron' => ''] + $url, 'cron'],
            'Cron ungültig' => [['cron' => '61 * * * *'] + $url, 'cron'],
            'Cron zu lang' => [['cron' => str_repeat('*', 101)] + $url, 'cron'],
            'Cron ohne Termin' => [['cron' => '0 0 30 2 *'] + $url, 'cron'],
            'Cron keine Zeichenkette' => [['cron' => ['* * * * *']] + $url, 'cron'],
            'Zeitzone unbekannt' => [['timezone' => 'Mars/Olympus'] + $url, 'timezone'],
            'Zeitzone Abkürzung' => [['timezone' => 'CEST'] + $url, 'timezone'],
            'is_enabled als Zahl' => [['is_enabled' => 1] + $url, 'is_enabled'],
            'catch_up als Text' => [['catch_up' => 'true'] + $url, 'catch_up'],
            'Überlappung unbekannt' => [['overlap_policy' => 'wait'] + $url, 'overlap_policy'],
            'Wiederholungen 11' => [['retry_count' => 11] + $url, 'retry_count'],
            'Wiederholungen negativ' => [['retry_count' => -1] + $url, 'retry_count'],
            'Wiederholungen als Text' => [['retry_count' => '3'] + $url, 'retry_count'],
            'Abstand 0' => [['retry_delay_seconds' => 0] + $url, 'retry_delay_seconds'],
            'Abstand 3601' => [['retry_delay_seconds' => 3601] + $url, 'retry_delay_seconds'],
            'Methode klein' => [['http' => ['method' => 'get']] + $url, 'http.method'],
            'Methode unbekannt' => [['http' => ['method' => 'TRACE']] + $url, 'http.method'],
            'Zeitlimit 0' => [['http' => ['timeout_seconds' => 0]] + $url, 'http.timeout_seconds'],
            'Zeitlimit über Maximum' => [['http' => ['timeout_seconds' => 301]] + $url, 'http.timeout_seconds'],
            'Zeitlimit als Text' => [['http' => ['timeout_seconds' => '30']] + $url, 'http.timeout_seconds'],
            'Statuscodes mit Leerzeichen' => [['http' => ['expected_status' => '200, 204']] + $url, 'http.expected_status'],
            'Statuscode 600' => [['http' => ['expected_status' => '600']] + $url, 'http.expected_status'],
            'Statusbereich umgekehrt' => [['http' => ['expected_status' => '299-200']] + $url, 'http.expected_status'],
            'Statuscodes 21 Bereiche' => [['http' => ['expected_status' => implode(',', array_fill(0, 21, '200'))]] + $url, 'http.expected_status'],
            'Statuscodes als Liste' => [['http' => ['expected_status' => [200]]] + $url, 'http.expected_status'],
            'Weiterleitungen 6' => [['http' => ['max_redirects' => 6]] + $url, 'http.max_redirects'],
            'Antwort speichern unbekannt' => [['http' => ['store_response' => 'always']] + $url, 'http.store_response'],
            'Anfrage fehlt' => [['request' => null], 'request'],
            'Anfrage als Liste' => [['request' => ['https://example.org/']], 'request'],
            'URL fehlt' => [['request' => ['headers' => []]], 'request.url'],
            'URL kein http' => [['request' => ['url' => 'ftp://example.org/']], 'request.url'],
            'URL mit Zugangsdaten' => [['request' => ['url' => 'https://user:pw@example.org/']], 'request.url'],
            'URL mit Fragment' => [['request' => ['url' => 'https://example.org/#x']], 'request.url'],
            'URL Dezimal-IP' => [['request' => ['url' => 'http://2130706433/']], 'request.url'],
            'URL localhost' => [['request' => ['url' => 'http://localhost/']], 'request.url'],
            'URL Metadaten-Name' => [['request' => ['url' => 'http://metadata.google.internal/']], 'request.url'],
            'URL Loopback-IP' => [['request' => ['url' => 'http://127.0.0.1/']], 'request.url'],
            'URL Metadaten-IP' => [['request' => ['url' => 'http://169.254.169.254/latest']], 'request.url'],
            'URL privates Netz ohne Freigabe' => [['request' => ['url' => 'http://192.168.1.5/']], 'request.url'],
            'Header-Name ungültig' => [['request' => ['url' => 'https://example.org/', 'headers' => [['name' => 'A B', 'value' => 'x']]]], 'request.headers[0].name'],
            'Header Host' => [['request' => ['url' => 'https://example.org/', 'headers' => [['name' => 'Host', 'value' => 'x']]]], 'request.headers[0].name'],
            'Header Proxy-*' => [['request' => ['url' => 'https://example.org/', 'headers' => [['name' => 'proxy-authorization', 'value' => 'x']]]], 'request.headers[0].name'],
            'Header-Wert mit Zeilenumbruch' => [['request' => ['url' => 'https://example.org/', 'headers' => [['name' => 'X-A', 'value' => "a\r\nB: c"]]]], 'request.headers[0].value'],
            'Header-Wert zu lang' => [['request' => ['url' => 'https://example.org/', 'headers' => [['name' => 'X-A', 'value' => str_repeat('a', 4097)]]]], 'request.headers[0].value'],
            'Header ohne value' => [['request' => ['url' => 'https://example.org/', 'headers' => [['name' => 'X-A']]]], 'request.headers[0]'],
            'Header kein Objekt' => [['request' => ['url' => 'https://example.org/', 'headers' => 'X-A: b']], 'request.headers'],
            '31 Header' => [['request' => ['url' => 'https://example.org/', 'headers' => array_fill(0, 31, ['name' => 'X-A', 'value' => 'b'])]], 'request.headers'],
            'Body zu groß' => [['http' => ['method' => 'POST'], 'request' => ['url' => 'https://example.org/', 'body' => str_repeat('a', 65537)]], 'request.body'],
            'Body kein Text' => [['http' => ['method' => 'POST'], 'request' => ['url' => 'https://example.org/', 'body' => ['a' => 1]]], 'request.body'],
            'Body bei GET' => [['request' => ['url' => 'https://example.org/', 'body' => 'x']], 'request.body'],
            'Body bei HEAD' => [['http' => ['method' => 'HEAD'], 'request' => ['url' => 'https://example.org/', 'body' => 'x']], 'request.body'],
            'Body kein UTF-8' => [['http' => ['method' => 'POST'], 'request' => ['url' => 'https://example.org/', 'body' => "\xFF\xFE"]], 'request.body'],
            'Unbekanntes Feld in request' => [['request' => ['url' => 'https://example.org/', 'verbose' => true]], 'request'],
            'Unbekanntes Feld in http' => [['http' => ['proxy' => 'x']] + $url, 'http'],
            'Unbekanntes Feld oben' => [['owner_id' => 5] + $url, 'body'],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInputAndNamesTheField(array $override, string $field): void
    {
        $input = $override + self::input(['category_id' => null]);
        if (array_key_exists('type', $override) && $override['type'] === null) {
            unset($input['type']);
        }

        $errors = $this->errorsOf($input);

        self::assertArrayHasKey($field, $errors, 'Felder: ' . implode(', ', array_keys($errors)));
    }

    public function testMessagesNeverContainTheInputValue(): void
    {
        $secret = self::secret('leak');
        $cases = [
            ['request' => ['url' => 'https://example.org/' . $secret . '#frag']],
            ['request' => ['url' => 'ftp://' . $secret . '/']],
            ['request' => ['url' => 'https://user:' . $secret . '@example.org/']],
            ['request' => ['url' => 'https://example.org/', 'headers' => [['name' => 'Host', 'value' => $secret]]]],
            ['request' => ['url' => 'https://example.org/', 'headers' => [['name' => 'X-A', 'value' => $secret . "\n"]]]],
            ['request' => ['url' => 'https://example.org/', 'headers' => [['name' => $secret . ' x', 'value' => 'a']]]],
            ['http' => ['method' => 'GET'], 'request' => ['url' => 'https://example.org/', 'body' => $secret]],
            ['http' => ['timeout_seconds' => $secret]],
            ['name' => $secret . "\n"],
            ['cron' => $secret],
            ['timezone' => $secret],
            ['category_id' => $secret],
            ['overlap_policy' => $secret],
            ['http' => ['expected_status' => $secret]],
            ['http' => [$secret => 1]],
            [$secret => 1],
            ['request' => [$secret => 1, 'url' => 'https://example.org/']],
        ];
        foreach ($cases as $override) {
            $errors = $this->errorsOf($override + self::input());
            $text = json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString($secret, $text);
            self::assertStringNotContainsString(rawurlencode($secret), $text);
            foreach (array_keys($errors) as $key) {
                self::assertStringNotContainsString('leak', (string) $key, 'auch der Feldpfad enthält keine Eingabe');
            }
        }
    }

    public function testTimeoutIsBoundedByTheGlobalMaximumAndTheMessageNamesIt(): void
    {
        $this->settings()->set(SettingKey::HttpMaxTimeout, 60, 1);

        $errors = $this->errorsOf(self::input(['http' => ['timeout_seconds' => 61]]));
        self::assertStringContainsString('höchstens 60 s', $errors['http.timeout_seconds'] ?? '');

        self::assertSame(60, $this->draft(self::input(['http' => ['timeout_seconds' => 60]]))->http()->timeoutSeconds);
        $this->settings()->set(SettingKey::HttpMaxTimeout, 10, 1);
        self::assertSame(10, $this->draft(self::input())->http()->timeoutSeconds, 'Standard 30 s wird auf das Maximum begrenzt');
    }

    public function testCategoryUnknownAndNotAllowedLookTheSame(): void
    {
        $onlyA = CategoryScope::only(['A']);

        $unknown = $this->errorsOf(self::input(['category_id' => 99]), $onlyA);
        $foreign = $this->errorsOf(self::input(['category_id' => 2]), $onlyA);
        $none = $this->errorsOf(self::input(['category_id' => null]), $onlyA);

        self::assertSame($unknown, $foreign);
        self::assertSame($unknown, $none, 'ohne Kategorie nur uneingeschränkt');
        self::assertSame('Kategorie unbekannt oder nicht erlaubt.', $unknown['category_id'] ?? null);
        self::assertSame(1, $this->validator()->validate(self::input(['category_id' => 1]), $onlyA, null)->categoryId);
        self::assertArrayHasKey('category_id', $this->errorsOf(self::input(['category_id' => 1]), CategoryScope::nothing()), 'leerer Bereich heißt nichts, nie alles');
    }

    public function testFreigabeLetsAnInternalIpLiteralThrough(): void
    {
        $this->db()->execute("INSERT INTO http_internal_targets (kind, value, port, category_id, created_at) VALUES ('cidr', '192.168.1.0/24', 0, 1, '2026-10-07T00:00:00+00:00')");

        $ok = $this->draft(self::input(['category_id' => 1, 'request' => ['url' => 'http://192.168.1.5/x']]));
        self::assertSame('192.168.1.5', $ok->http()->host);

        $errors = $this->errorsOf(self::input(['category_id' => 2, 'request' => ['url' => 'http://192.168.1.5/x']]));
        self::assertArrayHasKey('request.url', $errors, 'Freigabe gilt nur für Kategorie A');
        self::assertStringNotContainsString('192.168', $errors['request.url'] ?? '', 'Meldung ohne IP');
    }

    public function testGetWithStoredBodyIsRejectedUnlessTheRequestIsReplaced(): void
    {
        $id = $this->jobs()->insert($this->draft(self::input(['http' => ['method' => 'POST'], 'request' => ['url' => 'https://example.org/', 'body' => 'x']])), 1);
        $existing = $this->jobs()->findVisible($id, CategoryScope::everything());
        self::assertNotNull($existing);
        $all = CategoryScope::everything();

        try {
            $this->validator()->validate(['http' => ['method' => 'GET']], $all, $existing);
            self::fail('GET mit gespeichertem Body muss abgelehnt werden.');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('http.method', $e->fields());
        }

        $draft = $this->validator()->validate(['http' => ['method' => 'GET'], 'request' => ['url' => 'https://example.org/']], $all, $existing);
        self::assertFalse($draft->http()->hasBody);
        self::assertNotNull($draft->payload);
    }

    public function testTypeCannotChangeOnUpdate(): void
    {
        $id = $this->jobs()->insert($this->draft(self::input()), 1);
        $existing = $this->jobs()->findVisible($id, CategoryScope::everything());
        self::assertNotNull($existing);

        $this->expectException(ValidationFailed::class);
        $this->validator()->validate(['type' => 'shell'], CategoryScope::everything(), $existing);
    }

    public function testCategoryMoveNeedsTheRightInTheNewCategory(): void
    {
        $id = $this->jobs()->insert($this->draft(self::input(['category_id' => 1])), 1);
        $existing = $this->jobs()->findVisible($id, CategoryScope::everything());
        self::assertNotNull($existing);

        try {
            $this->validator()->validate(['category_id' => 2], CategoryScope::only(['A']), $existing);
            self::fail('A nach B ohne Recht in B muss abgelehnt werden.');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('category_id', $e->fields());
        }
        self::assertSame(2, $this->validator()->validate(['category_id' => 2], CategoryScope::only(['A', 'B']), $existing)->categoryId);
    }
}
