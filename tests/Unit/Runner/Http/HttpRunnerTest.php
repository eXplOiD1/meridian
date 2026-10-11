<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Runner\Http;

use Meridian\Runner\Heartbeat;
use Meridian\Runner\Http\AddressPolicy;
use Meridian\Runner\Http\DbHttpJobSource;
use Meridian\Runner\Http\DbInternalTargetSource;
use Meridian\Job\InvalidJobConfig;
use Meridian\Job\StoreResponse;
use Meridian\Runner\Http\HttpPayload;
use Meridian\Runner\Http\HttpRunner;
use Meridian\Runner\Http\InvalidPayload;
use Meridian\Runner\Http\TargetGuard;
use Meridian\Runner\Http\TransportError;
use Meridian\Runner\Http\TransportRequest;
use Meridian\Runner\Http\TransportResponse;
use Meridian\Runner\JobType;
use Meridian\Runner\RunRequest;
use Meridian\Runner\RunResult;
use Meridian\Schedule\RunStatus;
use Meridian\Schedule\RunTrigger;
use Meridian\Security\SecretBox;
use Meridian\Security\SecretMasker;
use Meridian\Settings\ResponseStorage;
use Meridian\Tests\Support\CountingHeartbeat;
use Meridian\Tests\Support\FakeHostResolver;
use Meridian\Tests\Support\FakeRunSettings;
use Meridian\Tests\Support\FakeTransport;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HttpRunner ohne Netz: Fake-Resolver und Fake-Transport. Geprüft werden Hop-Schleife, SSRF je Hop, Freigaben mit
 * der gespeicherten Kategorie, Weiterleitungsregeln, Zeitlimit, Antwort speichern, Masker und feste Meldungen.
 * Namen unter `.invalid` (RFC 6761) sind nie wirklich auflösbar.
 */
final class HttpRunnerTest extends TestCase
{
    private const TEST_SECRET = 'TEST-SECRET-do-not-use-123';
    private const PUBLIC_IP = '93.184.215.14';
    private const PUBLIC_IP_2 = '93.184.215.15';

    private ?SchedulerHarness $harness = null;
    private ?SecretBox $boxInstance = null;
    private ?FakeHostResolver $resolver = null;
    private ?FakeRunSettings $settingsInstance = null;

    private function h(): SchedulerHarness
    {
        return $this->harness ??= new SchedulerHarness();
    }

    private function box(): SecretBox
    {
        // Zufälliger Schlüssel je Test, nie ein Literal im Quelltext.
        return $this->boxInstance ??= new SecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    private function dns(): FakeHostResolver
    {
        if ($this->resolver === null) {
            $this->resolver = new FakeHostResolver();
            $this->resolver->set('api.example.invalid', [self::PUBLIC_IP]);
            $this->resolver->set('other.example.invalid', [self::PUBLIC_IP_2]);
        }

        return $this->resolver;
    }

    private function settings(): FakeRunSettings
    {
        return $this->settingsInstance ??= new FakeRunSettings();
    }

    private function runner(FakeTransport $transport): HttpRunner
    {
        return new HttpRunner(
            new DbHttpJobSource($this->h()->db),
            $this->box(),
            $this->settings(),
            new TargetGuard($this->dns(), new AddressPolicy(new DbInternalTargetSource($this->h()->db))),
            $transport,
        );
    }

    /**
     * @param array<string, mixed>              $http
     * @param list<array{0: string, 1: string}> $headers
     */
    private function job(string $url, array $http = [], array $headers = [], ?string $body = null, ?int $category = null, string $type = 'http'): int
    {
        $config = json_encode(['v' => 1, 'http' => $http + [
            'method' => 'GET',
            'timeout_seconds' => 30,
            'expected_status' => [[200, 299]],
            'max_redirects' => 3,
            'store_response' => 'inherit',
            'target' => ['scheme' => 'https', 'host' => 'api.example.invalid', 'port' => 443],
            'display_url' => 'https://api.example.invalid/••••',
            'display_v' => 1,
            'has_headers' => $headers !== [],
            'header_count' => count($headers),
            'has_body' => $body !== null,
        ]], JSON_THROW_ON_ERROR);

        return $this->h()->addJob([
            'type' => $type,
            'category_id' => $category,
            'config_json' => $config,
            'payload_enc' => $this->box()->encrypt((new HttpPayload($url, $headers, $body))->toJson()),
        ]);
    }

    private function execute(HttpRunner $runner, int $job, ?Heartbeat $heartbeat = null, ?SecretMasker $masker = null): RunResult
    {
        return $runner->run(
            new RunRequest(1, $job, JobType::Http, RunTrigger::Schedule, 1),
            $heartbeat ?? new CountingHeartbeat(),
            $masker ?? new SecretMasker(),
            new \Meridian\Runner\NullLiveLog(),
        );
    }

    private static function hop(FakeTransport $transport, int $i): TransportRequest
    {
        self::assertArrayHasKey($i, $transport->requests);

        return $transport->requests[$i];
    }

    public function testSuccessfulRunWritesOnlyTargetStatusAndSize(): void
    {
        $transport = new FakeTransport(FakeTransport::response(200, 'antwort ' . self::TEST_SECRET, durationMs: 2210));
        $job = $this->job('https://api.example.invalid/hooks/' . self::TEST_SECRET . '?key=' . self::TEST_SECRET, headers: [['Authorization', 'Bearer ' . self::TEST_SECRET]]);

        $result = $this->execute($this->runner($transport), $job);

        self::assertSame(RunStatus::Ok, $result->status);
        self::assertSame(200, $result->httpStatus);
        self::assertNull($result->note);
        self::assertSame("→ GET https://api.example.invalid\n← 200 · 2,21 s · " . strlen('antwort ' . self::TEST_SECRET) . ' B', $result->output, 'ohne Speichern nur Status-Zeilen');
        self::assertSame([self::PUBLIC_IP], self::hop($transport, 0)->target->ips, 'gepinnt auf die geprüfte Adresse');
        self::assertSame([['Authorization', 'Bearer ' . self::TEST_SECRET]], self::hop($transport, 0)->headers());
    }

    public function testPayloadIsRegisteredInTheRunMaskerBeforeSending(): void
    {
        $masker = new SecretMasker();
        $seenMasked = null;
        $transport = new FakeTransport(FakeTransport::response(200));
        $transport->during = static function () use ($masker, &$seenMasked): void {
            $seenMasked = $masker->mask('x ' . self::TEST_SECRET . ' y');
        };
        $job = $this->job('https://api.example.invalid/a?token=' . self::TEST_SECRET);

        $this->execute($this->runner($transport), $job, null, $masker);

        self::assertSame('x •••• y', $seenMasked, 'registriert, bevor die Anfrage das System verlässt');
    }

    /**
     * Antwort speichern: alle 9 Kombinationen global × Job; `never` gewinnt immer.
     *
     * @return iterable<string, array{0: ResponseStorage, 1: StoreResponse, 2: bool}>
     */
    public static function storageMatrix(): iterable
    {
        $expected = [
            'off' => ['inherit' => false, 'on' => true, 'off' => false],
            'on' => ['inherit' => true, 'on' => true, 'off' => false],
            'never' => ['inherit' => false, 'on' => false, 'off' => false],
        ];
        foreach ($expected as $global => $byJob) {
            foreach ($byJob as $job => $stores) {
                yield $global . ' × ' . $job => [ResponseStorage::from($global), StoreResponse::from($job), $stores];
            }
        }
    }

    #[DataProvider('storageMatrix')]
    public function testResponseStorageGlobalTimesJob(ResponseStorage $global, StoreResponse $jobValue, bool $stores): void
    {
        $this->settings()->storage = $global;
        $transport = new FakeTransport(FakeTransport::response(200, 'Inhalt der Antwort'));
        $job = $this->job('https://api.example.invalid/', ['store_response' => $jobValue->value]);

        $result = $this->execute($this->runner($transport), $job);

        self::assertSame($stores, str_contains($result->output, 'Inhalt der Antwort'));
        self::assertSame($stores, HttpRunner::storesResponse($jobValue, $global));
    }

    public function testBinaryResponseIsNotStored(): void
    {
        $this->settings()->storage = ResponseStorage::On;
        $transport = new FakeTransport(FakeTransport::response(200, "\x00\x01\xFFbinär", contentType: 'application/octet-stream'));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/'));

        self::assertStringContainsString('[Binärinhalt, 9 B, nicht gespeichert]', $result->output);
        self::assertStringNotContainsString("\x00", $result->output);
    }

    public function testCutMultibyteCharacterIsNotMistakenForBinary(): void
    {
        $this->settings()->storage = ResponseStorage::On;
        $kept = str_repeat('ä', 10) . "\xC3";
        $transport = new FakeTransport(new TransportResponse(200, null, null, null, 'text/plain', $kept, 5000, false, 10));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/'));

        self::assertStringContainsString("Antwort (maskiert, gekürzt):\n" . str_repeat('ä', 10), $result->output);
    }

    public function testBodyLimitNoteAndEvaluationByStatus(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, null, null, null, 'text/plain', 'aaa', 10485760, true, 10));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/'));

        self::assertSame(RunStatus::Ok, $result->status);
        self::assertSame(HttpRunner::NOTE_BODY_LIMIT, $result->note);
        self::assertStringContainsString('über 10 MiB', $result->output);
    }

    public function testUnexpectedStatusFailsAndIsRetryable(): void
    {
        $transport = new FakeTransport(FakeTransport::response(500));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/', ['expected_status' => [[200, 299], [404, 404]]]));

        self::assertSame(RunStatus::Failed, $result->status);
        self::assertSame('Unerwarteter Statuscode 500 (erwartet: 200-299,404).', $result->note);
        self::assertSame(500, $result->httpStatus);
        self::assertTrue($result->retryable);
    }

    public function testTimeoutIsCappedAtTheGlobalMaximumWithNote(): void
    {
        $this->settings()->maxTimeout = 60;
        $transport = new FakeTransport(FakeTransport::response(200));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/', ['timeout_seconds' => 600]));

        self::assertSame(RunStatus::Ok, $result->status);
        self::assertSame('Zeitlimit auf das Maximum von 60 s begrenzt.', $result->note);
        self::assertLessThanOrEqual(60000, self::hop($transport, 0)->timeoutMs);
        self::assertGreaterThan(59000, self::hop($transport, 0)->timeoutMs);
        self::assertSame(1, $this->settings()->reads, 'Einstellungen je Lauf frisch gelesen');
    }

    public function testTimeoutOfTheTransportBecomesTimeoutStatus(): void
    {
        $this->settings()->maxTimeout = 10;
        $transport = new FakeTransport(TransportResponse::failure(TransportError::Timeout, 10000, 28));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/', ['timeout_seconds' => 30]));

        self::assertSame(RunStatus::Timeout, $result->status);
        self::assertSame('Zeitlimit von 10 s überschritten. Zeitlimit auf das Maximum von 10 s begrenzt.', $result->note);
        self::assertTrue($result->retryable);
    }

    public function testTimeoutCoversAllHopsTogether(): void
    {
        $transport = new FakeTransport(FakeTransport::response(302, location: '/weiter'), FakeTransport::response(200));
        $transport->during = static function (TransportRequest $r): void {
            if ($r->timeoutMs > 1500) {
                usleep(1_200_000);
            }
        };

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/', ['timeout_seconds' => 2]));

        self::assertSame(RunStatus::Ok, $result->status);
        self::assertLessThan(900, self::hop($transport, 1)->timeoutMs, 'zweiter Hop bekommt nur die Restzeit');
    }

    /**
     * @return iterable<string, array{0: TransportError, 1: RunStatus, 2: string, 3: bool}>
     */
    public static function transportErrors(): iterable
    {
        yield 'Verbindung' => [TransportError::Connect, RunStatus::Failed, HttpRunner::NOTE_CONNECT, true];
        yield 'TLS' => [TransportError::Tls, RunStatus::Failed, HttpRunner::NOTE_TLS, false];
        yield 'Pinning' => [TransportError::PinViolation, RunStatus::Failed, HttpRunner::NOTE_PIN, false];
        yield 'Header' => [TransportError::HeadersTooLarge, RunStatus::Failed, HttpRunner::NOTE_HEADERS, true];
        yield 'sonstiger' => [TransportError::Other, RunStatus::Failed, 'Übertragung fehlgeschlagen (curl-Fehler 7).', true];
    }

    #[DataProvider('transportErrors')]
    public function testTransportErrorsHaveFixedNotes(TransportError $error, RunStatus $status, string $note, bool $retryable): void
    {
        $transport = new FakeTransport(TransportResponse::failure($error, 5, 7));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/x?key=' . self::TEST_SECRET));

        self::assertSame($status, $result->status);
        self::assertSame($note, $result->note);
        self::assertSame($retryable, $result->retryable);
        self::assertStringNotContainsString(self::TEST_SECRET, $result->output . $result->note);
        self::assertStringNotContainsString(self::PUBLIC_IP, $result->output . $result->note);
    }

    public function testHeartbeatFalseStopsBeforeSending(): void
    {
        $transport = new FakeTransport();

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/'), new CountingHeartbeat(aliveFor: 0));

        self::assertSame([], $transport->requests);
        self::assertSame(HttpRunner::NOTE_ABORTED, $result->note);
    }

    // ---- SSRF je Hop ----

    public function testBlockedTargetFailsWithoutAddressAndIsNotRetried(): void
    {
        $this->dns()->set('intern.example.invalid', ['192.168.1.5']);
        $transport = new FakeTransport();

        $result = $this->execute($this->runner($transport), $this->job('https://intern.example.invalid/'));

        self::assertSame([], $transport->requests, 'nie verbunden');
        self::assertSame(RunStatus::Failed, $result->status);
        self::assertFalse($result->retryable);
        self::assertStringStartsWith('Ziel gesperrt:', (string) $result->note);
        self::assertStringContainsString('Ein Admin kann interne Ziele freigeben', (string) $result->note);
        self::assertStringNotContainsString('192.168', (string) $result->note);
    }

    public function testMixedPublicAndPrivateAnswerIsRejected(): void
    {
        $this->dns()->set('gemischt.example.invalid', [self::PUBLIC_IP, '10.0.0.1']);
        $transport = new FakeTransport();

        $result = $this->execute($this->runner($transport), $this->job('https://gemischt.example.invalid/'));

        self::assertSame([], $transport->requests);
        self::assertFalse($result->retryable);
    }

    public function testUnresolvableIsRetryable(): void
    {
        $transport = new FakeTransport();

        $result = $this->execute($this->runner($transport), $this->job('https://gibtsnicht.example.invalid/'));

        self::assertSame([], $transport->requests);
        self::assertSame('Ziel nicht auflösbar: Hostname prüfen.', $result->note);
        self::assertTrue($result->retryable);
    }

    public function testMetadataIsNeverReachableEvenWithReleases(): void
    {
        $this->h()->db->execute("INSERT INTO http_internal_targets (kind, value, port, created_at) VALUES ('host', 'meta.example.invalid', 0, '2026-10-07T00:00:00+00:00')");
        $this->dns()->set('meta.example.invalid', ['169.254.169.254']);
        $transport = new FakeTransport();

        $result = $this->execute($this->runner($transport), $this->job('http://meta.example.invalid/latest/meta-data/'));

        self::assertSame([], $transport->requests);
        self::assertStringContainsString('nie freigegeben werden kann', (string) $result->note);
    }

    public function testCategoryReleaseUsesTheStoredCategoryOfTheJob(): void
    {
        $a = $this->h()->addCategory('A');
        $b = $this->h()->addCategory('B');
        $this->h()->db->execute("INSERT INTO http_internal_targets (kind, value, port, category_id, created_at) VALUES ('cidr', '192.168.1.0/24', 0, :a, '2026-10-07T00:00:00+00:00')", ['a' => $a]);
        $this->dns()->set('nas.example.invalid', ['192.168.1.5']);
        $transport = new FakeTransport(FakeTransport::response(200));
        $runner = $this->runner($transport);

        $inA = $this->job('http://nas.example.invalid/', category: $a);
        $inB = $this->job('http://nas.example.invalid/', category: $b);
        $none = $this->job('http://nas.example.invalid/');

        self::assertSame(RunStatus::Ok, $this->execute($runner, $inA)->status);
        self::assertSame(['192.168.1.5'], self::hop($transport, 0)->target->ips);
        self::assertSame(RunStatus::Failed, $this->execute($runner, $inB)->status);
        self::assertSame(RunStatus::Failed, $this->execute($runner, $none)->status);
        self::assertCount(1, $transport->requests);

        // Job nach B verschoben: ab dem nächsten Lauf gesperrt (Kategorie frisch aus der Datenbank).
        $this->h()->db->execute('UPDATE jobs SET category_id = :b WHERE id = :j', ['b' => $b, 'j' => $inA]);
        self::assertSame(RunStatus::Failed, $this->execute($runner, $inA)->status);
        // Kategorie A gelöscht: Freigabe weg (CASCADE), Job ohne Kategorie bleibt gesperrt.
        $this->h()->db->execute('UPDATE jobs SET category_id = :a WHERE id = :j', ['a' => $a, 'j' => $inA]);
        self::assertSame(RunStatus::Ok, $this->execute($runner, $inA)->status);
        $this->h()->db->execute('DELETE FROM categories WHERE id = :a', ['a' => $a]);
        self::assertSame(RunStatus::Failed, $this->execute($runner, $inA)->status);
        self::assertCount(2, $transport->requests);
    }

    public function testRebindingOnlyThePinnedAnswerCounts(): void
    {
        $this->dns()->set('rebind.example.invalid', [self::PUBLIC_IP], ['127.0.0.1']);
        $transport = new FakeTransport(FakeTransport::response(200));

        $result = $this->execute($this->runner($transport), $this->job('https://rebind.example.invalid/'));

        self::assertSame(RunStatus::Ok, $result->status);
        self::assertSame([self::PUBLIC_IP], self::hop($transport, 0)->target->ips, 'curl bekommt nur die geprüfte Adresse');
        self::assertSame(1, $this->dns()->lookups['rebind.example.invalid'], 'genau eine Auflösung je Hop');
    }

    // ---- Weiterleitungen ----

    public function testSameOriginRedirectKeepsHeadersAndIsCheckedAgain(): void
    {
        $transport = new FakeTransport(FakeTransport::response(301, location: '/neu', durationMs: 120), FakeTransport::response(200, durationMs: 50));
        $job = $this->job('https://api.example.invalid/alt', headers: [['X-Key', self::TEST_SECRET]]);

        $result = $this->execute($this->runner($transport), $job);

        self::assertSame(RunStatus::Ok, $result->status);
        self::assertSame("→ GET https://api.example.invalid\n← 301 · 0,12 s · Weiterleitung 1 von 3 (gleicher Server)\n→ GET https://api.example.invalid\n← 200 · 0,05 s · 0 B", $result->output);
        self::assertSame([['X-Key', self::TEST_SECRET]], self::hop($transport, 1)->headers());
        self::assertSame(2, $this->dns()->lookups['api.example.invalid'], 'jeder Hop neu aufgelöst und geprüft');
    }

    public function testRedirectToOtherOriginDropsHeadersAndBody(): void
    {
        $transport = new FakeTransport(FakeTransport::response(302, location: 'https://other.example.invalid/x'), FakeTransport::response(200));
        $job = $this->job('https://api.example.invalid/', ['method' => 'POST'], [['Authorization', 'Bearer ' . self::TEST_SECRET]], '{"a":"' . self::TEST_SECRET . '"}');

        $result = $this->execute($this->runner($transport), $job);

        self::assertSame(RunStatus::Ok, $result->status);
        $second = self::hop($transport, 1);
        self::assertSame('GET', $second->method, '302 nach POST: GET ohne Body');
        self::assertSame([], $second->headers(), 'keine Job-Header an einen anderen Ursprung');
        self::assertNull($second->body());
        self::assertSame([self::PUBLIC_IP_2], $second->target->ips);
        self::assertStringContainsString('(anderer Server)', $result->output);
    }

    public function testHeadersStayDroppedAfterReturningToTheOriginalOrigin(): void
    {
        $transport = new FakeTransport(
            FakeTransport::response(302, location: 'https://other.example.invalid/'),
            FakeTransport::response(302, location: 'https://api.example.invalid/zurueck'),
            FakeTransport::response(200),
        );

        $this->execute($this->runner($transport), $this->job('https://api.example.invalid/', headers: [['X-Key', self::TEST_SECRET]]));

        self::assertSame([], self::hop($transport, 2)->headers());
    }

    public function testSeeOtherBecomesGetWithoutBody(): void
    {
        $transport = new FakeTransport(FakeTransport::response(303, location: '/fertig'), FakeTransport::response(200));

        $this->execute($this->runner($transport), $this->job('https://api.example.invalid/', ['method' => 'PUT'], [], 'daten'));

        self::assertSame('GET', self::hop($transport, 1)->method);
        self::assertNull(self::hop($transport, 1)->body());
    }

    public function testTemporaryRedirectKeepsMethodAndBodyOnSameOrigin(): void
    {
        $transport = new FakeTransport(FakeTransport::response(307, location: '/b'), FakeTransport::response(200));

        $this->execute($this->runner($transport), $this->job('https://api.example.invalid/a', ['method' => 'POST'], [], 'daten'));

        self::assertSame('POST', self::hop($transport, 1)->method);
        self::assertSame('daten', self::hop($transport, 1)->body());
    }

    public function testTemporaryRedirectWithBodyToOtherOriginFails(): void
    {
        $transport = new FakeTransport(FakeTransport::response(307, location: 'https://other.example.invalid/'));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/', ['method' => 'POST'], [], 'daten'));

        self::assertCount(1, $transport->requests);
        self::assertSame(HttpRunner::NOTE_REDIRECT_BODY, $result->note);
        self::assertFalse($result->retryable);
    }

    public function testDowngradeToHttpIsRefused(): void
    {
        $transport = new FakeTransport(FakeTransport::response(301, location: 'http://api.example.invalid/'));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/'));

        self::assertCount(1, $transport->requests);
        self::assertSame(HttpRunner::NOTE_REDIRECT_DOWNGRADE, $result->note);
        self::assertFalse($result->retryable);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidLocations(): iterable
    {
        yield 'Zugangsdaten' => ['https://user:' . self::TEST_SECRET . '@other.example.invalid/'];
        yield 'fremdes Schema' => ['ftp://other.example.invalid/'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'localhost' => ['http://localhost/'];
        yield 'numerische IP' => ['http://2130706433/'];
        yield 'Leerzeichen' => ['/a b'];
        yield 'leer' => ['#nur-fragment'];
    }

    #[DataProvider('invalidLocations')]
    public function testInvalidLocationIsRefused(string $location): void
    {
        $transport = new FakeTransport(FakeTransport::response(302, location: $location));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/'));

        self::assertCount(1, $transport->requests);
        self::assertSame(HttpRunner::NOTE_REDIRECT_INVALID, $result->note);
        self::assertFalse($result->retryable);
        self::assertStringNotContainsString(self::TEST_SECRET, $result->output . $result->note);
    }

    public function testRedirectToPrivateAddressIsBlocked(): void
    {
        $this->dns()->set('intern.example.invalid', ['10.1.2.3']);
        $transport = new FakeTransport(FakeTransport::response(302, location: 'https://intern.example.invalid/'));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/'));

        self::assertCount(1, $transport->requests);
        self::assertStringStartsWith('Ziel gesperrt:', (string) $result->note);
        self::assertFalse($result->retryable);
    }

    public function testRedirectToLoopbackLiteralIsBlocked(): void
    {
        $transport = new FakeTransport(FakeTransport::response(302, location: 'http://127.0.0.1:8080/admin'));

        $result = $this->execute($this->runner($transport), $this->job('http://api.example.invalid/'));

        self::assertCount(1, $transport->requests);
        self::assertStringStartsWith('Ziel gesperrt:', (string) $result->note);
    }

    public function testTooManyRedirectsFailWithoutRetry(): void
    {
        $transport = new FakeTransport(FakeTransport::response(302, location: '/schleife'));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/', ['max_redirects' => 2]));

        self::assertCount(3, $transport->requests, 'erster Hop plus zwei Weiterleitungen');
        self::assertSame('Unerwarteter Statuscode 302 (erwartet: 200-299). Weiterleitungen: 2 erlaubt.', $result->note);
        self::assertFalse($result->retryable);
    }

    public function testNoRedirectsAllowed(): void
    {
        $transport = new FakeTransport(FakeTransport::response(301, location: '/x'));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/', ['max_redirects' => 0]));

        self::assertCount(1, $transport->requests);
        self::assertSame('Unerwarteter Statuscode 301 (erwartet: 200-299). Weiterleitungen: 0 erlaubt.', $result->note);
    }

    public function testRelativeLocationsAreResolvedAgainstTheCurrentUrl(): void
    {
        $transport = new FakeTransport(
            FakeTransport::response(302, location: 'weiter?x=1'),
            FakeTransport::response(302, location: '?y=2'),
            FakeTransport::response(302, location: '//other.example.invalid/c'),
            FakeTransport::response(200),
        );

        $this->execute($this->runner($transport), $this->job('https://api.example.invalid/a/b', ['max_redirects' => 5]));

        self::assertSame('https://api.example.invalid/a/weiter?x=1', self::hop($transport, 1)->target->url->toUrl());
        self::assertSame('https://api.example.invalid/a/weiter?y=2', self::hop($transport, 2)->target->url->toUrl());
        self::assertSame('https://other.example.invalid/c', self::hop($transport, 3)->target->url->toUrl());
    }

    // ---- Einrichtungsfehler ----

    public function testPayloadWithWrongKeyFailsWithFixedNote(): void
    {
        $job = $this->job('https://api.example.invalid/');
        $this->boxInstance = new SecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $transport = new FakeTransport();

        $result = $this->execute($this->runner($transport), $job);

        self::assertSame([], $transport->requests);
        self::assertSame(InvalidPayload::unreadable()->getMessage(), $result->note);
        self::assertFalse($result->retryable);
    }

    public function testUnknownPayloadVersionFails(): void
    {
        $job = $this->job('https://api.example.invalid/');
        $this->h()->db->execute('UPDATE jobs SET payload_enc = :p WHERE id = :j', ['p' => $this->box()->encrypt('{"v":2,"url":"https://x.invalid/"}'), 'j' => $job]);

        $result = $this->execute($this->runner(new FakeTransport()), $job);

        self::assertSame(InvalidPayload::unknownVersion()->getMessage(), $result->note);
        self::assertFalse($result->retryable);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function brokenConfigs(): iterable
    {
        $rest = '"target":{"scheme":"https","host":"api.example.invalid","port":443},"display_url":"https://api.example.invalid/","display_v":1,"has_headers":false,"header_count":0,"has_body":false';
        yield 'kein JSON' => ['{'];
        yield 'ohne v' => ['{"http":{"method":"GET","timeout_seconds":30,"expected_status":[[200,299]],"max_redirects":3,"store_response":"inherit",' . $rest . '}}'];
        yield 'Version 2' => ['{"v":2,"http":{"method":"GET","timeout_seconds":30,"expected_status":[[200,299]],"max_redirects":3,"store_response":"inherit",' . $rest . '}}'];
        yield 'Methode' => ['{"v":1,"http":{"method":"TRACE","timeout_seconds":30,"expected_status":[[200,299]],"max_redirects":3,"store_response":"inherit",' . $rest . '}}'];
        yield 'Zeitlimit 0' => ['{"v":1,"http":{"method":"GET","timeout_seconds":0,"expected_status":[[200,299]],"max_redirects":3,"store_response":"inherit",' . $rest . '}}'];
        yield 'Zeitlimit Text' => ['{"v":1,"http":{"method":"GET","timeout_seconds":"30","expected_status":[[200,299]],"max_redirects":3,"store_response":"inherit",' . $rest . '}}'];
        yield 'Weiterleitungen 6' => ['{"v":1,"http":{"method":"GET","timeout_seconds":30,"expected_status":[[200,299]],"max_redirects":6,"store_response":"inherit",' . $rest . '}}'];
        yield 'Status leer' => ['{"v":1,"http":{"method":"GET","timeout_seconds":30,"expected_status":[],"max_redirects":3,"store_response":"inherit",' . $rest . '}}'];
        yield 'Status verkehrt' => ['{"v":1,"http":{"method":"GET","timeout_seconds":30,"expected_status":[[299,200]],"max_redirects":3,"store_response":"inherit",' . $rest . '}}'];
        yield 'Speichern unbekannt' => ['{"v":1,"http":{"method":"GET","timeout_seconds":30,"expected_status":[[200,299]],"max_redirects":3,"store_response":"always",' . $rest . '}}'];
        yield 'Feld fehlt' => ['{"v":1,"http":{"method":"GET","timeout_seconds":30,"expected_status":[[200,299]],"max_redirects":3,"store_response":"inherit"}}'];
    }

    #[DataProvider('brokenConfigs')]
    public function testBrokenConfigFailsWithoutDefaults(string $config): void
    {
        $job = $this->job('https://api.example.invalid/');
        $this->h()->db->execute('UPDATE jobs SET config_json = :c WHERE id = :j', ['c' => $config, 'j' => $job]);
        $transport = new FakeTransport();

        $result = $this->execute($this->runner($transport), $job);

        self::assertSame([], $transport->requests);
        self::assertSame((new InvalidJobConfig())->getMessage(), $result->note);
        self::assertFalse($result->retryable);
    }

    public function testMissingOrNonHttpJobFails(): void
    {
        $runner = $this->runner(new FakeTransport());

        self::assertSame(HttpRunner::NOTE_NOT_HTTP, $this->execute($runner, 999)->note);
        self::assertSame(HttpRunner::NOTE_NOT_HTTP, $this->execute($runner, $this->job('https://api.example.invalid/', type: 'shell'))->note);
    }

    public function testStoredBodyWithGetFails(): void
    {
        $transport = new FakeTransport();

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/', ['method' => 'GET'], [], 'body'));

        self::assertSame([], $transport->requests);
        self::assertSame(HttpRunner::NOTE_BODY_FOR_GET, $result->note);
    }

    public function testHeadRequestNeverStoresABody(): void
    {
        $this->settings()->storage = ResponseStorage::On;
        $transport = new FakeTransport(FakeTransport::response(200, 'unerwartet'));

        $result = $this->execute($this->runner($transport), $this->job('https://api.example.invalid/', ['method' => 'HEAD']));

        self::assertSame('HEAD', self::hop($transport, 0)->method);
        self::assertStringNotContainsString('unerwartet', $result->output);
    }
}
