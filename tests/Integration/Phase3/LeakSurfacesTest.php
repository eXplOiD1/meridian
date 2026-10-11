<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Phase3;

use Meridian\Http\JobController;
use Meridian\Job\JobDraft;
use Meridian\Runner\Http\CurlTransport;
use Meridian\Runner\Http\HttpPayload;
use Meridian\Runner\Http\InvalidPayload;
use Meridian\Runner\Http\InvalidUrl;
use Meridian\Runner\Http\HttpTransport;
use Meridian\Runner\Http\ParsedUrl;
use Meridian\Runner\Http\PinnedTarget;
use Meridian\Runner\Http\TransportRequest;
use Meridian\Runner\Http\TransportResponse;
use Meridian\Runner\Http\UrlPolicy;
use Meridian\Runner\Heartbeat;
use Meridian\Security\CategoryScope;
use Meridian\Security\SecretBox;
use Meridian\Security\SecretException;
use Meridian\Security\SecretMasker;
use Meridian\Tests\Support\FakeHostResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S11, Teil 2: die übrigen Ausgabestellen. Abgelehnte Eingaben (4xx/422), Fehlerantworten, CLI, Ausnahmen
 * (Meldung, Stacktrace mit Argumenten, Debug-Ausgabe, Serialisierung) und das Fehlerprotokoll.
 */
final class LeakSurfacesTest extends TestCase
{
    use Pipeline;

    private string $previousIgnoreArgs = '1';

    protected function setUp(): void
    {
        $this->pipelineUp();
        // Strengster Fall: Ausnahmen tragen ihre Argumente (php.ini-development); #[SensitiveParameter] muss greifen.
        $this->previousIgnoreArgs = (string) ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->previousIgnoreArgs);
        $this->pipelineDown();
    }

    // ------------------------------------------------------------------------------------------------ API 4xx

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): array
    {
        $long = str_repeat('a', 2100);

        return [
            'Schema ftp' => [['request' => ['url' => 'ftp://example.org/{s}']], 'request.url'],
            'Zugangsdaten in der URL' => [['request' => ['url' => 'http://user:{s}@example.org/']], 'request.url'],
            'Fragment mit Geheimnis' => [['request' => ['url' => 'http://example.org/x#{s}']], 'request.url'],
            'Leerzeichen in der URL' => [['request' => ['url' => 'http://example.org/{s} x']], 'request.url'],
            'Nicht-ASCII-Host' => [['request' => ['url' => 'http://exämple.org/{s}']], 'request.url'],
            'URL zu lang' => [['request' => ['url' => 'http://example.org/' . $long . '{s}']], 'request.url'],
            'Loopback-Literal mit Geheimnis im Pfad' => [['request' => ['url' => 'http://127.0.0.1/{s}']], 'request.url'],
            'Privates Literal' => [['request' => ['url' => 'http://10.0.0.1/{s}?token={s}']], 'request.url'],
            'Metadaten-Literal' => [['request' => ['url' => 'http://169.254.169.254/{s}']], 'request.url'],
            'Header-Name ungültig' => [['request' => ['url' => 'http://example.org/', 'headers' => [['name' => 'Bad Name', 'value' => '{s}']]]], 'request.headers'],
            'Verbotener Header Host' => [['request' => ['url' => 'http://example.org/', 'headers' => [['name' => 'Host', 'value' => '{s}']]]], 'request.headers'],
            'Header-Wert mit Zeilenumbruch' => [['request' => ['url' => 'http://example.org/', 'headers' => [['name' => 'X-A', 'value' => "x\r\n{s}"]]]], 'request.headers'],
            'Body bei GET' => [['request' => ['url' => 'http://example.org/', 'body' => '{s}']], 'request.body'],
            'Body kein Text' => [['request' => ['url' => 'http://example.org/', 'body' => ['{s}']]], 'request.body'],
            'Unbekanntes Feld heißt wie das Geheimnis' => [['{s}' => 1], 'body'],
            'Unbekanntes Feld in request' => [['request' => ['url' => 'http://example.org/', '{s}' => 1]], 'request'],
            'Name mit Steuerzeichen' => [['name' => "x\x01{s}", 'request' => ['url' => 'http://example.org/']], 'name'],
            'Cron ist das Geheimnis' => [['cron' => '{s}', 'request' => ['url' => 'http://example.org/']], 'cron'],
            'Zeitzone ist das Geheimnis' => [['timezone' => '{s}', 'request' => ['url' => 'http://example.org/']], 'timezone'],
            'Methode ist das Geheimnis' => [['http' => ['method' => '{s}'], 'request' => ['url' => 'http://example.org/']], 'http.method'],
            'Erwartete Status ist das Geheimnis' => [['http' => ['expected_status' => '{s}'], 'request' => ['url' => 'http://example.org/']], 'http.expected_status'],
            'Kategorie ist das Geheimnis' => [['category_id' => '{s}', 'request' => ['url' => 'http://example.org/']], 'category_id'],
            'Typ shell mit Geheimnis im Namen' => [['type' => 'shell', 'name' => '{s}', 'request' => ['url' => 'http://example.org/']], 'type'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRejectedJobInputNeverEchoesTheSecret(array $body, string $field): void
    {
        $secret = self::token('input');
        $body = json_decode(str_replace('{s}', $secret, (string) json_encode($body)), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        $body = $body + self::jobBody(['url' => 'http://example.org/']);
        // GET-Fälle (Body bei GET): Standard-Methode ist GET.
        foreach (['admin', 'operatorA', 'observer'] as $who) {
            $response = $this->req($who, 'POST', '/api/jobs', $body);
            self::assertContains($response->getStatusCode(), [403, 422], $who . ': ' . $this->plain($response));
            if ($who === 'admin') {
                self::assertSame(422, $response->getStatusCode(), $this->plain($response));
            }
        }
        self::assertSame(0, $this->jobCount(), 'nichts gespeichert');
        $this->req(null, 'POST', '/api/jobs', $body);
        $this->assertNoLeak([$secret], $field);
    }

    public function testRawBrokenRequestsNeverEchoTheSecret(): void
    {
        $secret = self::token('raw');
        $cases = [
            '{"request": {"url": "' . $secret,                      // abgeschnittenes JSON
            '[' . str_repeat('[', 40) . '"' . $secret . '"',        // zu tief und kaputt
            '"' . $secret . '"',                                    // kein Objekt
            '{"name": "' . $secret . '", "name": 1',                // doppelt und kaputt
            str_repeat('x', 170_000) . $secret,                     // über 160 KiB
        ];
        foreach ($cases as $content) {
            $response = $this->rawApi('admin', 'POST', '/api/jobs', $content);
            self::assertGreaterThanOrEqual(400, $response->getStatusCode());
            self::assertLessThan(500, $response->getStatusCode(), 'ungültige Eingabe ist nie ein 500');
            $this->capture('api', $response->headers . "\n" . $response->getContent());
        }
        $this->assertNoLeak([$secret], 'kaputte Anfragekörper');
    }

    public function testSecretsInPathQueryAndMethodOfRequestsNeverComeBack(): void
    {
        $secret = self::token('url');
        $jobId = $this->createJob(self::jobBody(['url' => 'http://93.184.216.34/x']));
        $paths = [
            ['GET', '/api/jobs/' . $secret],
            ['GET', '/api/jobs/' . $jobId . '/' . $secret],
            ['GET', '/api/runs/' . $secret],
            ['GET', '/api/jobs?category_id=' . $secret . '&sort=' . $secret . '&type=' . $secret . '&enabled=' . $secret . '&limit=' . $secret],
            ['GET', '/api/jobs?' . $secret . '=1'],
            ['GET', '/api/jobs/' . $jobId . '/runs?status=' . $secret . '&before_id=' . $secret . '&limit=' . $secret],
            ['GET', '/api/schedule/preview?cron=' . rawurlencode($secret) . '&timezone=' . $secret . '&count=' . $secret],
            ['GET', '/api/categories?permission=' . $secret],
            ['GET', '/api/audit?action=' . $secret . '&limit=' . $secret . '&before_id=' . $secret],
            ['DELETE', '/api/settings/internal-targets/' . $secret],
            ['PUT', '/api/settings/' . $secret],
            ['GET', '/api/settings/' . $secret],
            ['PATCH', '/api/jobs/' . $jobId . '?token=' . $secret],
            ['POST', '/api/' . $secret],
            ['GET', '/app/' . $secret],
            ['GET', '/' . $secret . '?token=' . $secret],
        ];
        foreach ([null, 'observer', 'operatorA', 'admin'] as $who) {
            foreach ($paths as [$method, $path]) {
                $response = $this->req($who, $method, $path, $method === 'PUT' ? ['value' => $secret] : []);
                self::assertLessThan(500, $response->getStatusCode(), $method . ' ' . $path . ' als ' . ($who ?? 'anonym'));
            }
        }
        // Freigaben: Wert und Notiz sind Eingabe des Admins, nie Geheimnis; ungültige Werte werden nicht gespiegelt.
        foreach ([['kind' => 'cidr', 'value' => $secret, 'port' => 80], ['kind' => 'host', 'value' => $secret . '_' . $secret, 'port' => 80], ['kind' => $secret, 'value' => '10.0.0.0/8'], ['kind' => 'cidr', 'value' => '10.0.0.0/8', 'port' => $secret], ['kind' => 'cidr', 'value' => '10.0.0.0/8', 'category_id' => $secret], ['kind' => 'cidr', 'value' => '10.0.0.0/8', $secret => 1]] as $body) {
            $response = $this->req('admin', 'POST', '/api/settings/internal-targets', $body);
            self::assertSame(422, $response->getStatusCode(), $this->plain($response));
        }
        $this->assertNoLeak([$secret], 'Pfad und Query');
    }

    // ------------------------------------------------------------------------------------------------ CLI

    public function testCliOutputsNeverEchoInputOrPaths(): void
    {
        $secret = self::token('cli');
        $closed = \Meridian\Tests\Support\LocalHttpServer::freePort();
        $this->cli(['http:internal-targets', 'add', 'cidr', '127.0.0.1/32', '--port=' . $closed, '--category=A', '--note=Test']);
        $this->cli(['http:internal-targets', 'list']);
        // Ungültige Eingaben mit dem Geheimnis in jedem Argument.
        $this->cli(['http:internal-targets', 'add', 'cidr', $secret]);
        $this->cli(['http:internal-targets', 'add', 'host', $secret . '_' . $secret]);
        $this->cli(['http:internal-targets', 'add', $secret, '10.0.0.0/8']);
        $this->cli(['http:internal-targets', 'add', 'cidr', '10.0.0.0/8', '--category=' . $secret]);
        $this->cli(['http:internal-targets', 'add', 'cidr', '10.0.0.0/8', '--port=' . $secret]);
        $this->cli(['http:internal-targets', $secret]);
        $this->cli(['http:internal-targets', 'remove', $secret]);
        $this->cli(['http:internal-targets', 'remove', '1']);
        // Start ohne lesbaren Schlüssel: die Meldung nennt weder Pfad noch Inhalt.
        $this->cli(['scheduler:run', '--once', '--inline-worker', '-vvv'], ['MERIDIAN_KEY_FILE' => '/nonexistent/' . $secret]);
        $weak = $this->dir . '/weak-' . $secret;
        file_put_contents($weak, \Meridian\Security\KeyLoader::generate() . "\n");
        chmod($weak, 0o644);
        $this->cli(['scheduler:run', '--once', '--inline-worker', '-vvv'], ['MERIDIAN_KEY_FILE' => $weak]);
        file_put_contents($weak, $secret . "\n");
        chmod($weak, 0o600);
        $this->cli(['scheduler:run', '--once', '--inline-worker', '-vvv'], ['MERIDIAN_KEY_FILE' => $weak]);
        $this->cli(['scheduler:run', '--once', '--inline-worker', '-vvv'], ['MERIDIAN_TIMEZONE' => $secret]);
        $this->cli(['scheduler:run', '--once', '--inline-worker', '-vvv'], ['MERIDIAN_ENV' => $secret]);
        unlink($weak);
        $this->assertNoLeak([$secret], 'CLI');
    }

    // ------------------------------------------------------------------------------------------------ Ausnahmen

    /**
     * Prüft alle Darstellungen einer Ausnahme (Meldung, __toString, Trace mit Argumenten, print_r, Vorgänger).
     *
     * @param list<string> $secrets
     */
    private function assertExceptionClean(\Throwable $e, array $secrets, string $where): void
    {
        $texts = [
            'getMessage' => $e->getMessage(),
            'toString' => (string) $e,
            'trace' => $e->getTraceAsString(),
            'print_r' => print_r($e, true),
            'traceArgs' => print_r($e->getTrace(), true),
        ];
        $previous = $e->getPrevious();
        while ($previous !== null) {
            $texts['previous'] = ($texts['previous'] ?? '') . (string) $previous;
            $previous = $previous->getPrevious();
        }
        $this->assertNoLeakIn($texts, $secrets, $where);
    }

    public function testExceptionsFromTheRealCodePathsCarryNoSecrets(): void
    {
        $s = $this->newSecrets();
        $secrets = array_values($s);
        $urlSecret = 'http://user:' . $s['path'] . '@example.org/' . $s['query'];
        // Keine Hilfsfunktion, die die Aufrufe als Argument erhält: sonst stünden die Closures samt Variablen im Trace.
        $cases = [];
        $caught = 0;
        $cases['UrlPolicy::parse (Zugangsdaten)'] = fn () => (new UrlPolicy())->parse($urlSecret);
        $cases['UrlPolicy::parse (Schema)'] = fn () => (new UrlPolicy())->parse('ftp://example.org/' . $s['path'] . '?key=' . $s['query']);
        $cases['HttpPayload (URL)'] = fn () => new HttpPayload('http://exämple.org/' . $s['path'], [['Authorization', 'Bearer ' . $s['header']]], $s['body']);
        $cases['HttpPayload (Header)'] = fn () => new HttpPayload('http://example.org/' . $s['path'], [['Bad Name', $s['header']]], $s['body']);
        $cases['HttpPayload (Host-Header)'] = fn () => new HttpPayload('http://example.org/' . $s['path'], [['Host', $s['header']]], $s['body']);
        $cases['HttpPayload (Body-Größe)'] = fn () => new HttpPayload('http://example.org/' . $s['path'], [['X-A', $s['header']]], str_repeat('x', 70_000) . $s['body']);
        $cases['HttpPayload::fromJson (Version)'] = fn () => HttpPayload::fromJson((string) json_encode(['v' => 2, 'url' => 'http://example.org/' . $s['path'], 'headers' => [['A', $s['header']]], 'body' => $s['body']]));
        $cases['HttpPayload::fromJson (Form)'] = fn () => HttpPayload::fromJson((string) json_encode(['v' => 1, 'url' => 'http://example.org/' . $s['path'], 'headers' => [['A', $s['header'], 'x']], 'body' => $s['body']]));
        $cases['HttpPayload::fromJson (kaputtes JSON)'] = fn () => HttpPayload::fromJson('{"v":1,"url":"http://example.org/' . $s['path'] . '","body":"' . $s['body']);
        $cases['SecretBox::decrypt (Müll)'] = fn () => $this->box()->decrypt('v1:' . base64_encode(random_bytes(48)));
        $cases['SecretBox::decrypt (falscher Schlüssel)'] = fn () => (new SecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)))->decrypt($this->box()->encrypt($s['body']));
        $cases['SecretBox::decrypt (Version)'] = fn () => $this->box()->decrypt('v9:' . base64_encode(random_bytes(48)));
        $cases['JobValidator (URL gesperrt)'] = fn () => $this->policyValidator()->validate(self::jobBody(['url' => 'http://10.0.0.1/' . $s['path'] . '?token=' . $s['query'], 'headers' => [['name' => 'Authorization', 'value' => 'Bearer ' . $s['header']]], 'body' => $s['body']], ['method' => 'POST']), CategoryScope::everything(), null);
        $cases['JobValidator (Header)'] = fn () => $this->policyValidator()->validate(self::jobBody(['url' => 'http://example.org/' . $s['path'], 'headers' => [['name' => 'Host', 'value' => $s['header']]], 'body' => $s['body']], ['method' => 'POST']), CategoryScope::everything(), null);
        foreach ($cases as $where => $call) {
            try {
                $call();
            } catch (\Throwable $e) {
                ++$caught;
                $this->assertExceptionClean($e, $secrets, $where);

                continue;
            }
            self::fail($where . ': erwartete eine Ausnahme');
        }
        self::assertSame(15, $caught);

        // Test des Versuchsaufbaus: Ohne #[SensitiveParameter] stünden die Argumente im Trace.
        $leaky = static function (string $value): never {
            throw new \RuntimeException('x');
        };
        try {
            $leaky($s['path']);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($s['path'], print_r($e->getTrace(), true), 'der Versuchsaufbau sieht Argumente');
        }
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function debugRenderers(): array
    {
        return [['print_r'], ['var_dump'], ['var_export'], ['json_encode'], ['serialize'], ['debug_zval_dump']];
    }


    #[DataProvider('debugRenderers')]
    public function testObjectsThatHoldSecretsDoNotShowThemInDebugOutput(string $renderer): void
    {
        $s = $this->newSecrets();
        $url = 'http://example.org/' . $s['path'] . '?token=' . $s['query'];
        $headers = [['Authorization', 'Bearer ' . $s['header']], ['X-Custom-Token', $s['header2']]];
        $payload = new HttpPayload($url, $headers, $s['body']);
        $parsed = (new UrlPolicy())->parse($url);
        $pinned = new PinnedTarget($parsed, ['93.184.216.34']);
        $request = new TransportRequest('POST', $pinned, $headers, $s['body'], 1000);
        $masker = new SecretMasker();
        $payload->registerIn($masker);
        $draft = $this->policyValidator()->validate(self::jobBody(['url' => $url, 'headers' => [['name' => 'Authorization', 'value' => 'Bearer ' . $s['header']]], 'body' => $s['body']], ['method' => 'POST']), CategoryScope::everything(), null);
        $box = new SecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $objects = ['HttpPayload' => $payload, 'ParsedUrl' => $parsed, 'PinnedTarget' => $pinned, 'TransportRequest' => $request, 'SecretMasker' => $masker, 'JobDraft' => $draft, 'SecretBox' => $box];
        $leaking = [];
        foreach ($objects as $name => $object) {
            try {
                $text = match ($renderer) {
                    'print_r' => print_r($object, true),
                    'var_dump' => (static function () use ($object): string {
                        ob_start();
                        var_dump($object);

                        return (string) ob_get_clean();
                    })(),
                    'var_export' => var_export($object, true),
                    'json_encode' => (string) json_encode($object, JSON_PARTIAL_OUTPUT_ON_ERROR),
                    'serialize' => serialize($object),
                    'debug_zval_dump' => (static function () use ($object): string {
                        ob_start();
                        debug_zval_dump($object);

                        return (string) ob_get_clean();
                    })(),
                    default => '',
                };
            } catch (\Throwable $e) {
                // Eine Ausnahme statt einer Ausgabe ist die sichere Antwort (z. B. „nicht serialisierbar“).
                $text = (string) $e;
            }
            if (self::findLeak([$renderer . ' ' . $name => $text], array_values($s)) !== null) {
                $leaking[] = $name;
            }
        }

        self::assertSame([], $leaking, $renderer . ': Darstellung gibt Geheimnisse preis');
    }

    // ------------------------------------------------------------------------------------------------ Laufzeitfehler

    public function testRunnerExceptionWithSecretInMessageIsStoredAsFixedNote(): void
    {
        $s = $this->newSecrets();
        $jobId = $this->createJob(self::jobBody(['url' => 'http://93.184.216.34/x'], []));
        // Wird ersetzt: öffentliche Adresse ist am Schnittstellen-Fake egal, der Transport wirft mit der URL.
        $this->replacePayload($jobId, 'http://93.184.216.34/' . $s['path'] . '?token=' . $s['query']);
        $runId = $this->enqueue($jobId);
        $resolver = new FakeHostResolver();
        $throwing = new class ($s['query']) implements HttpTransport {
            public function __construct(#[\SensitiveParameter] private readonly string $secret)
            {
            }

            public function send(TransportRequest $request, Heartbeat $heartbeat): TransportResponse
            {
                throw new \RuntimeException('Verbindung zu http://93.184.216.34/?token=' . $this->secret . ' fehlgeschlagen');
            }
        };

        // Der echte Worker mit einem werfenden Transport: die Meldung der Ausnahme darf nirgends landen.
        $events = $this->tickWithTransport($resolver, $throwing);

        self::assertCount(1, $events);
        $row = $this->runRow($runId);
        self::assertSame('failed', $row['status']);
        self::assertSame(\Meridian\Schedule\Worker::NOTE_RUNNER_ERROR, $row['note']);
        $this->sweepApi([$jobId], [$runId]);
        $this->assertNoLeak([...array_values($s)], 'Ausnahme im Transport');
    }

    public function testFailingResultStorageNeverPrintsTheExceptionText(): void
    {
        $s = $this->newSecrets();
        $server = $this->server();
        $this->releaseLoopback($server->port);
        $jobId = $this->createJob(self::jobBody(['url' => $server->url('/echo/' . $s['path'] . '?token=' . $s['query'])], []));
        $runId = $this->enqueue($jobId);
        // Ein Auslöser in der Datenbank wirft beim Speichern des Ergebnisses eine Ausnahme mit Text (kein Geheimnis
        // des Jobs, aber ein offensichtlich unechtes Test-Geheimnis als Fehlertext).
        $marker = 'TEST-GEHEIMNIS-unecht-trigger-4711';
        $this->db()->execute("CREATE TRIGGER leak_probe BEFORE UPDATE ON runs WHEN NEW.status <> 'running' BEGIN SELECT RAISE(ABORT, 'TEST-GEHEIMNIS-unecht-trigger-4711'); END");

        $process = $this->schedulerProcess();

        self::assertStringNotContainsString($marker, $process['out'] . $process['err']);
        self::assertSame('running', $this->runRow($runId)['status'], 'Ergebnis nicht gespeichert (Auslöser), Lauf hängt bis zum Abbruch');
        $this->db()->execute('DROP TRIGGER leak_probe');
        $this->assertNoLeak(array_values($s), 'Speichern scheitert');
        // Der Text der Ausnahme steht im Schema der Testdatenbank (Auslöser), sonst nirgends: nicht in der Ausgabe des Dienstes.
        $this->assertNoLeakIn(['scheduler-stdout' => $this->captured['scheduler-stdout'], 'scheduler-stderr' => $this->captured['scheduler-stderr'], 'api' => $this->captured['api'] ?? ''], [$marker], 'Ausnahmetext');
    }

    public function testKernelErrorResponseHidesTheMessageInProduction(): void
    {
        $marker = 'TEST-GEHEIMNIS-unecht-kernel-4711';
        $this->db()->execute("CREATE TRIGGER leak_probe BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'TEST-GEHEIMNIS-unecht-kernel-4711'); END");
        $s = $this->newSecrets();

        $response = $this->req('admin', 'POST', '/api/jobs', self::jobBody(['url' => 'http://93.184.216.34/' . $s['path'], 'headers' => [['name' => 'X-Custom-Token', 'value' => $s['header']]]]));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Interner Fehler.'], $this->json($response));
        $this->db()->execute('DROP TRIGGER leak_probe');
        $this->assertNoLeak(array_values($s), 'Kernel 500');
        $this->assertNoLeakIn(['api' => $this->captured['api'] ?? ''], [$marker], 'Kernel 500, Antwort');
    }

    private function policyValidator(): \Meridian\Job\JobValidator
    {
        return new \Meridian\Job\JobValidator(
            new \Meridian\Job\CategoryRepository($this->db()),
            new \Meridian\Settings\Settings($this->db(), $this->clock()),
            $this->clock(),
            new \Meridian\Runner\Http\AddressPolicy(new \Meridian\Runner\Http\DbInternalTargetSource($this->db())),
        );
    }

    /**
     * @return list<\Meridian\Schedule\RunEvent>
     */
    private function tickWithTransport(FakeHostResolver $resolver, HttpTransport $transport): array
    {
        $clock = new \Meridian\Auth\SystemClock();
        $db = $this->db();
        $lease = new \Meridian\Schedule\SchedulerLease($db, $clock, \Meridian\Schedule\SchedulerLease::newOwnerId());
        $runners = new \Meridian\Runner\RunnerRegistry();
        $runners->register(\Meridian\Runner\JobType::Http, new \Meridian\Runner\Http\HttpRunner(
            new \Meridian\Runner\Http\DbHttpJobSource($db),
            $this->box(),
            new \Meridian\Runner\Http\StoredHttpRunSettings(new \Meridian\Settings\Settings($db, $clock)),
            new \Meridian\Runner\Http\TargetGuard($resolver, new \Meridian\Runner\Http\AddressPolicy(new \Meridian\Runner\Http\DbInternalTargetSource($db))),
            $transport,
        ));
        $scheduler = new \Meridian\Schedule\Scheduler(
            $lease,
            new \Meridian\Schedule\Planner($db, $clock, $lease),
            new \Meridian\Schedule\StaleRuns($db, $clock, $lease),
            new \Meridian\Schedule\Worker($db, $clock, new \Meridian\Schedule\WorkerIdentity($lease->owner(), \Meridian\Runner\JobType::Http), [\Meridian\Runner\JobType::Http], $runners, new \Meridian\Schedule\DbRunAuthorizer($db, new \Meridian\User\UserRepository($db, new \Meridian\Security\PasswordHasher()))),
        );
        try {
            return $scheduler->tick(static fn (): bool => false);
        } finally {
            $scheduler->shutdown();
        }
    }
}
