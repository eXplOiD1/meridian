<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Runner\Http;

use Meridian\Runner\Http\AddressPolicy;
use Meridian\Runner\Http\CurlTransport;
use Meridian\Runner\Http\DbHttpJobSource;
use Meridian\Runner\Http\DbInternalTargetSource;
use Meridian\Runner\Http\HttpPayload;
use Meridian\Runner\Http\HttpRunner;
use Meridian\Runner\Http\StoredHttpRunSettings;
use Meridian\Runner\Http\TargetGuard;
use Meridian\Runner\JobType;
use Meridian\Security\SecretBox;
use Meridian\Settings\Settings;
use Meridian\Tests\Support\FakeHostResolver;
use Meridian\Tests\Support\LocalHttpServer;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\TestCase;

/**
 * Worker → HttpRunner → CurlTransport → lokaler `php -S` auf 127.0.0.1. Loopback ist nur erreichbar, weil der Test
 * genau `127.0.0.1/32` mit dem Port des Test-Servers freigibt; Einstellungen und Freigaben kommen aus der echten
 * Datenbank und gelten ohne Neustart.
 */
final class HttpRunnerEndToEndTest extends TestCase
{
    private const TEST_SECRET = 'TEST-SECRET-do-not-use-123';

    private static ?LocalHttpServer $server = null;

    private ?SchedulerHarness $harness = null;

    private ?SecretBox $boxInstance = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new LocalHttpServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private static function port(): int
    {
        self::assertNotNull(self::$server);

        return self::$server->port;
    }

    private function h(): SchedulerHarness
    {
        if ($this->harness === null) {
            $this->harness = new SchedulerHarness();
            self::assertTrue($this->harness->lease->acquire());
            $box = $this->box();
            $dns = new FakeHostResolver();
            $dns->set('meridian-test.invalid', ['127.0.0.1']);
            $this->harness->runners->register(JobType::Http, new HttpRunner(
                new DbHttpJobSource($this->harness->db),
                $box,
                new StoredHttpRunSettings(new Settings($this->harness->db, $this->harness->clock)),
                new TargetGuard($dns, new AddressPolicy(new DbInternalTargetSource($this->harness->db))),
                new CurlTransport('Meridian/test'),
            ));
        }

        return $this->harness;
    }

    private function box(): SecretBox
    {
        return $this->boxInstance ??= new SecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    private function releaseLoopback(): void
    {
        $this->h()->db->execute(
            "INSERT INTO http_internal_targets (kind, value, port, created_at) VALUES ('cidr', '127.0.0.1/32', :port, '2026-10-07T00:00:00+00:00')",
            ['port' => self::port()],
        );
    }

    private function setting(string $key, string $json): void
    {
        $this->h()->db->execute(
            "INSERT INTO settings (key, value_json, updated_at) VALUES (:k, :v, '2026-10-07T00:00:00+00:00') ON CONFLICT (key) DO UPDATE SET value_json = excluded.value_json",
            ['k' => $key, 'v' => $json],
        );
    }

    /**
     * @param array<string, mixed>              $http
     * @param list<array{0: string, 1: string}> $headers
     */
    private function job(string $path, array $http = [], array $headers = [], ?string $body = null): int
    {
        $url = 'http://meridian-test.invalid:' . self::port() . $path;
        $config = json_encode(['v' => 1, 'http' => $http + [
            'method' => 'GET', 'timeout_seconds' => 10, 'expected_status' => [[200, 299]], 'max_redirects' => 3, 'store_response' => 'inherit',
            'target' => ['scheme' => 'http', 'host' => 'meridian-test.invalid', 'port' => self::port()],
            'display_url' => 'http://meridian-test.invalid:' . self::port() . '/••••', 'display_v' => 1,
            'has_headers' => $headers !== [], 'header_count' => count($headers), 'has_body' => $body !== null,
        ]], JSON_THROW_ON_ERROR);

        return $this->h()->addJob(['config_json' => $config, 'payload_enc' => $this->box()->encrypt((new HttpPayload($url, $headers, $body))->toJson())]);
    }

    /**
     * @return array{status: string, note: ?string, output: ?string, http_status: mixed}
     */
    private function runOnce(int $job): array
    {
        $this->h()->addRun($job, 'queued');
        $this->h()->worker->work(SchedulerHarness::never());
        $row = $this->h()->db->fetchOne('SELECT status, note, output, http_status FROM runs WHERE job_id = :j ORDER BY id DESC LIMIT 1', ['j' => $job]);
        self::assertNotNull($row);

        return [
            'status' => is_string($row['status']) ? $row['status'] : '',
            'note' => is_string($row['note']) ? $row['note'] : null,
            'output' => is_string($row['output']) ? $row['output'] : null,
            'http_status' => $row['http_status'],
        ];
    }

    public function testLoopbackIsBlockedUntilReleasedAndReleaseWorksWithoutRestart(): void
    {
        $job = $this->job('/status/200');

        $blocked = $this->runOnce($job);
        self::assertSame('failed', $blocked['status']);
        self::assertStringStartsWith('Ziel gesperrt:', (string) $blocked['note']);

        $this->releaseLoopback();
        $ok = $this->runOnce($job);
        self::assertSame('ok', $ok['status']);
        self::assertSame(200, $ok['http_status']);
    }

    public function testEchoedSecretsNeverReachTheStoredRun(): void
    {
        $this->releaseLoopback();
        $this->setting('http.response_storage', '"on"');
        $job = $this->job(
            '/echo?key=' . self::TEST_SECRET,
            ['method' => 'POST'],
            [['Authorization', 'Bearer ' . self::TEST_SECRET], ['X-Other', 'wert-' . self::TEST_SECRET]],
            '{"password":"' . self::TEST_SECRET . '"}',
        );

        $run = $this->runOnce($job);

        self::assertSame('ok', $run['status']);
        $stored = (string) json_encode($this->h()->db->fetchAll('SELECT * FROM runs'));
        self::assertStringContainsString('method=POST', (string) $run['output'], 'Antwort gespeichert');
        foreach ([self::TEST_SECRET, rawurlencode(self::TEST_SECRET), base64_encode(self::TEST_SECRET)] as $form) {
            self::assertStringNotContainsString($form, $stored);
        }
        self::assertStringNotContainsString('/echo', $stored, 'nie der Pfad');
    }

    public function testLoweredMaximumAbortsTheRunAtTheMaximumWithNote(): void
    {
        $this->releaseLoopback();
        $this->setting('http.max_timeout_seconds', '1');
        $job = $this->job('/slow?ms=4000', ['timeout_seconds' => 30]);

        $started = microtime(true);
        $run = $this->runOnce($job);

        self::assertSame('timeout', $run['status']);
        self::assertSame('Zeitlimit von 1 s überschritten. Zeitlimit auf das Maximum von 1 s begrenzt.', $run['note']);
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    public function testElevenMegabytesAreCutWithNote(): void
    {
        $this->releaseLoopback();
        $job = $this->job('/big?bytes=' . (11 * 1024 * 1024), ['timeout_seconds' => 20]);

        $run = $this->runOnce($job);

        self::assertSame('ok', $run['status']);
        self::assertSame(HttpRunner::NOTE_BODY_LIMIT, $run['note']);
    }

    public function testBinaryResponseIsNotStored(): void
    {
        $this->releaseLoopback();
        $this->setting('http.response_storage', '"on"');

        $run = $this->runOnce($this->job('/binary'));

        self::assertStringContainsString('[Binärinhalt, ', (string) $run['output']);
        self::assertStringNotContainsString('binär', str_replace('Binär', '', (string) $run['output']));
    }

    public function testRedirectIsFollowedManuallyAndCheckedAgain(): void
    {
        $this->releaseLoopback();
        $job = $this->job('/redirect?code=302&to=' . rawurlencode('/status/201'));

        $run = $this->runOnce($job);

        self::assertSame('ok', $run['status']);
        self::assertSame(201, $run['http_status']);
        self::assertStringContainsString('Weiterleitung 1 von 3 (gleicher Server)', (string) $run['output']);
    }

    public function testRedirectToAnotherLoopbackPortIsBlocked(): void
    {
        $this->releaseLoopback();
        $other = LocalHttpServer::freePort();
        $job = $this->job('/redirect?code=302&to=' . rawurlencode('http://127.0.0.1:' . $other . '/'));

        $run = $this->runOnce($job);

        self::assertSame('failed', $run['status']);
        self::assertStringStartsWith('Ziel gesperrt:', (string) $run['note'], 'Freigabe gilt nur für den freigegebenen Port');
        self::assertStringNotContainsString('127.0.0.1', (string) $run['note'] . $run['output']);
    }
}
