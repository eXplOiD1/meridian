<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Runner\Http;

use Meridian\Runner\Http\CurlHop;
use Meridian\Runner\Http\CurlTransport;
use Meridian\Runner\Http\PinnedTarget;
use Meridian\Runner\Http\TransportError;
use Meridian\Runner\Http\TransportRequest;
use Meridian\Runner\Http\TransportResponse;
use Meridian\Runner\Http\UrlPolicy;
use Meridian\Tests\Support\CountingHeartbeat;
use Meridian\Tests\Support\LocalHttpServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CurlTransport gegen einen lokalen `php -S` auf 127.0.0.1 (nur Loopback, kein echtes Netz). Die SSRF-Prüfung
 * davor macht der Runner; hier geht es um einen Hop: Optionen, Festhalten der Adresse, Grenzen, Herzschlag.
 */
final class CurlTransportTest extends TestCase
{
    private const TEST_SECRET = 'TEST-SECRET-do-not-use-123';

    private static ?LocalHttpServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new LocalHttpServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private static function server(): LocalHttpServer
    {
        self::assertNotNull(self::$server);

        return self::$server;
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     * @param non-empty-list<string>|null       $ips
     */
    private static function request(string $url, string $method = 'GET', array $headers = [], ?string $body = null, int $timeoutMs = 5000, ?array $ips = null): TransportRequest
    {
        $parsed = (new UrlPolicy())->parse($url);
        /** @var 'GET'|'POST'|'PUT'|'PATCH'|'DELETE'|'HEAD' $method */
        return new TransportRequest($method, new PinnedTarget($parsed, $ips ?? ['127.0.0.1']), $headers, $body, $timeoutMs);
    }

    private static function send(TransportRequest $request, ?CountingHeartbeat $heartbeat = null): TransportResponse
    {
        return (new CurlTransport('Meridian/test'))->send($request, $heartbeat ?? new CountingHeartbeat());
    }

    /**
     * @return iterable<string, array{0: 'GET'|'POST'|'PUT'|'PATCH'|'DELETE'|'HEAD', 1: string|null}>
     */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET', null];
        yield 'POST' => ['POST', '{"a":1}'];
        yield 'POST ohne Body' => ['POST', null];
        yield 'PUT' => ['PUT', 'put-body'];
        yield 'PATCH' => ['PATCH', 'patch-body'];
        yield 'DELETE' => ['DELETE', null];
        yield 'DELETE mit Body' => ['DELETE', 'del-body'];
    }

    #[DataProvider('methods')]
    public function testMethodsHeadersAndBodyReachTheTarget(string $method, ?string $body): void
    {
        /** @var 'GET'|'POST'|'PUT'|'PATCH'|'DELETE'|'HEAD' $method */
        $response = self::send(self::request(self::server()->url('/echo'), $method, [['X-Token', self::TEST_SECRET], ['Content-Type', 'application/json']], $body));

        self::assertNull($response->error);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('method=' . $method . "\n", $response->body());
        self::assertStringContainsString('header x-token: ' . self::TEST_SECRET, $response->body());
        self::assertStringContainsString('header user-agent: Meridian/test', $response->body());
        self::assertStringNotContainsString('header expect:', $response->body(), 'kein „Expect: 100-continue“');
        self::assertStringNotContainsString('header accept-encoding:', $response->body(), 'keine Entpackung angefordert');
        self::assertStringContainsString('body=' . ($body ?? '') . "\n", $response->body());
        self::assertSame(strlen($response->body()), $response->bodyBytes);
    }

    public function testHeadSendsNoBodyAndReadsNone(): void
    {
        $response = self::send(self::request(self::server()->url('/status/204'), 'HEAD'));

        self::assertNull($response->error);
        self::assertSame(204, $response->status);
        self::assertSame('', $response->body());
    }

    public function testRedirectsAreNeverFollowed(): void
    {
        $response = self::send(self::request(self::server()->url('/redirect?code=302&to=/status/500')));

        self::assertNull($response->error);
        self::assertSame(302, $response->status);
        self::assertSame('/status/500', $response->location());
    }

    public function testStatusCodesArePassedThrough(): void
    {
        $response = self::send(self::request(self::server()->url('/status/503')));

        self::assertNull($response->error);
        self::assertSame(503, $response->status);
        self::assertSame('status 503', $response->body());
        self::assertSame('text/plain; charset=utf-8', $response->contentType);
    }

    /**
     * Beweis für das Festhalten: `.invalid` (RFC 6761) ist nie auflösbar. Die Anfrage kommt trotzdem an, also hat
     * curl kein DNS gefragt, sondern die festgehaltene Adresse benutzt.
     */
    public function testPinnedAddressIsUsedInsteadOfDns(): void
    {
        $response = self::send(self::request('http://meridian-test.invalid:' . self::server()->port . '/echo', ips: ['127.0.0.1']));

        self::assertNull($response->error);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('header host: meridian-test.invalid:' . self::server()->port, $response->body(), 'Host-Header bleibt der Name');
    }

    public function testPinnedAddressWinsOverAnyOtherAddress(): void
    {
        // Festgehalten ist eine Adresse, auf der niemand lauscht: kein Ausweichen auf eine andere.
        $response = self::send(self::request('http://localhost-like.invalid:' . self::server()->port . '/echo', ips: ['127.0.0.2'], timeoutMs: 2000));

        self::assertNotNull($response->error);
        self::assertSame(0, $response->status);
    }

    public function testProxyEnvironmentIsIgnored(): void
    {
        $closed = LocalHttpServer::freePort();
        $names = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'];
        foreach ($names as $name) {
            putenv($name . '=http://127.0.0.1:' . $closed);
        }
        putenv('no_proxy=');
        try {
            $response = self::send(self::request(self::server()->url('/status/200')));
        } finally {
            foreach ([...$names, 'no_proxy'] as $name) {
                putenv($name);
            }
        }

        self::assertNull($response->error, 'der (nicht erreichbare) Proxy wurde nicht benutzt');
        self::assertSame(200, $response->status);
    }

    public function testFixedSecurityOptions(): void
    {
        $options = (new CurlTransport('Meridian/1.0'))->options(self::request('https://example.com/a?b=c', 'POST', [['Authorization', 'Bearer x']], 'body', 9000, ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']));

        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $options[CURLOPT_MAXREDIRS]);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertTrue($options[CURLOPT_FRESH_CONNECT]);
        self::assertTrue($options[CURLOPT_FORBID_REUSE]);
        self::assertTrue($options[CURLOPT_NOSIGNAL]);
        self::assertFalse($options[CURLOPT_UNRESTRICTED_AUTH]);
        self::assertArrayNotHasKey(CURLOPT_ENCODING, $options, 'keine Entpackung');
        self::assertArrayNotHasKey(CURLOPT_COOKIEFILE, $options);
        self::assertSame(['example.com:443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'], $options[CURLOPT_RESOLVE]);
        self::assertSame(9000, $options[CURLOPT_TIMEOUT_MS]);
        self::assertSame(9000, $options[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame('Meridian/1.0', $options[CURLOPT_USERAGENT]);
        self::assertSame(['Authorization: Bearer x', 'Expect:'], $options[CURLOPT_HTTPHEADER]);
        self::assertTrue($options[CURLOPT_POST]);
        self::assertSame('body', $options[CURLOPT_POSTFIELDS]);
        self::assertSame('http,https', $options[CURLOPT_PROTOCOLS_STR]);
        self::assertSame('http,https', $options[CURLOPT_REDIR_PROTOCOLS_STR]);

        $long = (new CurlTransport())->options(self::request('http://[2001:db8::1]:8080/', timeoutMs: 60000, ips: ['2001:db8::1']));
        self::assertSame(10000, $long[CURLOPT_CONNECTTIMEOUT_MS], 'Verbindungsaufbau höchstens 10 s');
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $long, 'IP-Literal: kein Name aufzulösen');
    }

    public function testConnectedAddressMustBeAPinnedOne(): void
    {
        $hop = new CurlHop(['127.0.0.1', '2001:db8::1'], 8080);

        self::assertTrue($hop->allowsPeer('127.0.0.1', 8080));
        self::assertTrue($hop->allowsPeer('2001:0db8:0:0::1', 8080), 'kanonisch verglichen');
        self::assertFalse($hop->allowsPeer('127.0.0.2', 8080));
        self::assertFalse($hop->allowsPeer('127.0.0.1', 8081), 'anderer Port');
        self::assertFalse($hop->allowsPeer('', 8080));
        self::assertFalse($hop->allowsPeer('::ffff:127.0.0.1', 8080), 'andere Schreibweise ist eine andere Adresse');
    }

    public function testBodyIsLimitedWhileReading(): void
    {
        $response = self::send(self::request(self::server()->url('/big?bytes=' . (11 * 1024 * 1024)), timeoutMs: 20000));

        self::assertNull($response->error, 'nach Statuscode bewertet');
        self::assertSame(200, $response->status);
        self::assertTrue($response->bodyLimitReached);
        self::assertSame(CurlTransport::MAX_BODY_BYTES, $response->bodyBytes);
        self::assertSame(CurlTransport::KEEP_BODY_BYTES, strlen($response->body()), 'nur der Anfang wird behalten');
    }

    public function testLargeBodyBelowTheLimitIsCountedButNotKept(): void
    {
        $response = self::send(self::request(self::server()->url('/big?bytes=200000')));

        self::assertNull($response->error);
        self::assertFalse($response->bodyLimitReached);
        self::assertSame(200000, $response->bodyBytes);
        self::assertSame(CurlTransport::KEEP_BODY_BYTES, strlen($response->body()));
    }

    public function testOversizedHeadersAbort(): void
    {
        $response = self::send(self::request(self::server()->url('/bigheaders')));

        self::assertSame(TransportError::HeadersTooLarge, $response->error);
    }

    public function testTimeout(): void
    {
        $started = microtime(true);
        $response = self::send(self::request(self::server()->url('/slow?ms=3000'), timeoutMs: 1000));

        self::assertSame(TransportError::Timeout, $response->error);
        self::assertLessThan(2.5, microtime(true) - $started);
    }

    public function testHeartbeatWhileWaiting(): void
    {
        $heartbeat = new CountingHeartbeat();
        $response = self::send(self::request(self::server()->url('/drip?ms=3000')), $heartbeat);

        self::assertNull($response->error);
        self::assertGreaterThanOrEqual(3, $heartbeat->beats, 'Antwort nach 3 s: mindestens drei Herzschläge');
        self::assertLessThan(1.5, $heartbeat->maxGap(), 'Abstand ≈ 1 s (curl_multi_select)');
    }

    public function testHeartbeatFalseAbortsQuickly(): void
    {
        $heartbeat = new CountingHeartbeat(aliveFor: 1);
        $started = microtime(true);
        $response = self::send(self::request(self::server()->url('/slow?ms=10000'), timeoutMs: 20000), $heartbeat);

        self::assertSame(TransportError::Aborted, $response->error);
        self::assertLessThan(2.0, microtime(true) - $started, 'Abbruch in höchstens 2 s');
    }

    public function testConnectionRefused(): void
    {
        $response = self::send(self::request('http://127.0.0.1:' . LocalHttpServer::freePort() . '/'));

        self::assertSame(TransportError::Connect, $response->error);
        self::assertSame(0, $response->status);
    }

    public function testTlsFailureIsReportedAsTls(): void
    {
        // https gegen einen reinen HTTP-Server: der TLS-Handshake scheitert.
        $response = self::send(self::request('https://127.0.0.1:' . self::server()->port . '/', timeoutMs: 3000));

        self::assertSame(TransportError::Tls, $response->error);
    }

    public function testRequestAndResponseNeverShowSecretsInDebugOutput(): void
    {
        $request = self::request(self::server()->url('/echo?key=' . self::TEST_SECRET), 'POST', [['Authorization', 'Bearer ' . self::TEST_SECRET]], self::TEST_SECRET);
        $response = self::send($request);

        foreach ([print_r($request, true), var_export($request->__debugInfo(), true), print_r($response, true)] as $dump) {
            self::assertStringNotContainsString(self::TEST_SECRET, $dump);
        }
        try {
            serialize($request);
            self::fail('serialize muss werfen');
        } catch (\LogicException) {
        }
    }
}
