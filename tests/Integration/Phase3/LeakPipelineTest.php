<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Phase3;

use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S11, Teil 1: Leak-Gesamtsuite über die ganze Pipeline (docs/decisions/0003, E2, §5.3; CLAUDE.md Regel 4).
 *
 * Ein Test-Geheimnis steckt in URL-Pfad, Query, Header-Werten und Body; der lokale Zielserver spiegelt alles
 * zurück (Antwort, Antwort-Header, Fehlerseiten, Weiterleitungen). Der Job wird per API angelegt und vom echten
 * Scheduler-Prozess (`bin/meridian scheduler:run --once -vvv`) mit dem echten HttpRunner ausgeführt. Danach darf
 * das Geheimnis in keiner Ausgabestelle stehen.
 */
final class LeakPipelineTest extends TestCase
{
    use Pipeline;

    protected function setUp(): void
    {
        $this->pipelineUp();
    }

    protected function tearDown(): void
    {
        $this->pipelineDown();
    }

    /**
     * @param array<string, string> $s
     *
     * @return array<string, mixed>
     */
    private static function request(array $s, string $url): array
    {
        return [
            'url' => $url,
            'headers' => [
                ['name' => 'Authorization', 'value' => 'Bearer ' . $s['header']],
                ['name' => 'X-Custom-Token', 'value' => $s['header2']],
                ['name' => 'Content-Type', 'value' => 'application/json'],
            ],
            'body' => json_encode(['password' => $s['body'], 'nested' => ['token' => $s['body2']]], JSON_THROW_ON_ERROR),
        ];
    }

    private function setStorage(string $mode): void
    {
        $response = $this->req('admin', 'PUT', '/api/settings/http.response_storage', ['value' => $mode]);
        self::assertSame(200, $response->getStatusCode(), $this->plain($response));
    }

    private function makeDue(int $jobId): void
    {
        $this->db()->execute('UPDATE jobs SET next_run_at = :t WHERE id = :id', ['t' => Timestamp::format(new \DateTimeImmutable('-30 seconds')), 'id' => $jobId]);
    }

    public function testSecretInPathQueryHeaderAndBodyNeverAppearsAnywhere(): void
    {
        $s = $this->newSecrets();
        $serverSecret = self::token('server');
        $server = $this->server($serverSecret);
        $this->releaseLoopback($server->port);
        $url = $server->url('/echo/' . $s['path'] . '?token=' . $s['query']);
        $jobId = $this->createJob(self::jobBody(self::request($s, $url), ['method' => 'POST']));

        // Manuell, Testlauf und geplant: dreimal derselbe echte Dienst.
        $runs = [$this->enqueue($jobId)];
        $this->schedulerProcess();
        $runs[] = $this->enqueue($jobId, 'admin', 'test');
        $this->schedulerProcess();
        $this->makeDue($jobId);
        $this->schedulerProcess();
        $scheduled = $this->db()->fetchOne("SELECT id FROM runs WHERE job_id = :j AND trigger = 'schedule'", ['j' => $jobId]);
        self::assertNotNull($scheduled, 'der geplante Lauf entstand');
        self::assertIsInt($scheduled['id']);
        $runs[] = $scheduled['id'];

        // Positivkontrollen: Der Test belegt, dass die Geheimnisse wirklich unterwegs waren.
        self::assertSame(3, $server->hitCount());
        foreach ($server->hits() as $hit) {
            self::assertStringContainsString($s['path'], $hit);
            self::assertStringContainsString($s['query'], $hit);
        }
        foreach ($runs as $runId) {
            $row = $this->runRow($runId);
            self::assertSame('ok', $row['status'], (string) $row['note']);
            self::assertSame(200, $row['http_status']);
            self::assertMatchesRegularExpression('~^→ POST http://127\.0\.0\.1:' . $server->port . "\n← 200 · [0-9,]+ s · [0-9 ]+ B$~D", (string) $row['output'], 'Ausgabe: nur Ziel, Status, Dauer, Größe');
        }

        // Roundtrip über eine neue Verbindung: die Anfrage liegt verschlüsselt, mit allen Geheimnissen, in der Datenbank.
        $fresh = Connection::open($this->dir . '/meridian.sqlite');
        $stored = $fresh->fetchOne('SELECT config_json, payload_enc FROM jobs WHERE id = :id', ['id' => $jobId]);
        self::assertNotNull($stored);
        self::assertIsString($stored['payload_enc']);
        self::assertIsString($stored['config_json']);
        $plain = $this->box()->decrypt($stored['payload_enc']);
        foreach ($s as $secret) {
            self::assertStringContainsString($secret, $plain);
        }
        $config = json_decode($stored['config_json'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('http://127.0.0.1:' . $server->port . '/••••?••••', self::dig($config, 'http', 'display_url'), 'display_url nach Regel E2, ohne Geheimnisteil');
        self::assertSame(['scheme' => 'http', 'host' => '127.0.0.1', 'port' => $server->port], self::dig($config, 'http', 'target'));

        // Ändern ohne und mit neuer Anfrage, danach Löschen: jede Antwort und jeder Audit-Eintrag.
        self::assertSame(200, $this->req('admin', 'PUT', '/api/jobs/' . $jobId, ['name' => 'Umbenannt'])->getStatusCode());
        self::assertSame(200, $this->req('admin', 'PUT', '/api/jobs/' . $jobId, ['request' => self::request($s, $url)])->getStatusCode());
        self::assertSame(200, $this->req('admin', 'POST', '/api/jobs/' . $jobId . '/disable')->getStatusCode());
        self::assertSame(200, $this->req('admin', 'POST', '/api/jobs/' . $jobId . '/enable')->getStatusCode());

        $this->sweepApi([$jobId], $runs);
        $audit = $this->plain($this->req('admin', 'GET', '/api/audit?limit=200'));
        self::assertStringContainsString('job.created', $audit);
        self::assertStringContainsString('job.updated', $audit);
        self::assertStringContainsString('Anfrage ersetzt', $audit);

        self::assertSame(204, $this->req('admin', 'DELETE', '/api/jobs/' . $jobId)->getStatusCode());
        $this->req('admin', 'GET', '/api/audit?limit=200');

        $this->assertNoLeak([...array_values($s), $serverSecret], 'Hauptlauf');
        // Der Dienst gibt nur Job-, Lauf-Nummer und Status aus.
        self::assertMatchesRegularExpression('/^(?:Job \d+, Lauf \d+: \w+\n|\n)*$/D', $this->captured['scheduler-stdout']);
        self::assertSame('', trim($this->captured['scheduler-stderr']));
    }

    public function testStoredResponseIsMaskedAndAnUnknownServerSecretInTokenFormToo(): void
    {
        $s = $this->newSecrets();
        $serverSecret = self::token('server');
        $server = $this->server($serverSecret);
        $this->releaseLoopback($server->port);
        $this->setStorage('on');
        $url = $server->url('/echo/' . $s['path'] . '?token=' . $s['query']);
        $jobId = $this->createJob(self::jobBody(self::request($s, $url), ['method' => 'POST', 'store_response' => 'on']));

        $run = $this->enqueue($jobId);
        $this->schedulerProcess();

        $row = $this->runRow($run);
        self::assertSame('ok', $row['status'], (string) $row['note']);
        // Positivkontrolle: die Antwort ist wirklich gespeichert und enthält die gespiegelte Anfrage.
        self::assertStringContainsString("Antwort (maskiert):\nmethod=POST", (string) $row['output']);
        self::assertStringContainsString('header authorization: ••••', (string) $row['output']);
        self::assertStringContainsString('header x-custom-token: ••••', (string) $row['output']);
        self::assertStringContainsString('token=••••', (string) $row['output'], 'Wert, den nur der Server kennt, über das Muster token=');

        $this->sweepApi([$jobId], [$run]);
        $this->assertNoLeak([...array_values($s), $serverSecret], 'gespeicherte Antwort');
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string, string}>
     */
    public static function failureScenarios(): array
    {
        return [
            'Statuscode 500, Fehlerseite spiegelt alles' => ['/fail/{path}?token={query}', [], 'failed', 'Unerwarteter Statuscode 500'],
            'Weiterleitung innerhalb desselben Servers' => ['/moved/{path}?token={query}', [], 'ok', ''],
            'Weiterleitungen aufgebraucht (0 erlaubt)' => ['/moved/{path}?token={query}', ['max_redirects' => 0], 'failed', 'Weiterleitungen: 0 erlaubt'],
            'Zeitlimit' => ['/slow/{path}?token={query}', ['timeout_seconds' => 1], 'timeout', 'Zeitlimit von 1 s überschritten'],
            'Weiterleitung auf 10.0.0.1' => ['/go/{b64:http://10.0.0.1}/{path}?token={query}', [], 'failed', 'Ziel gesperrt'],
            'Weiterleitung auf Metadaten-Adresse' => ['/go/{b64:http://169.254.169.254/latest/meta-data}/{path}?token={query}', [], 'failed', 'Ziel gesperrt'],
            'Weiterleitung auf anderen Loopback-Port' => ['/go/{b64:http://127.0.0.1:1}/{path}?token={query}', [], 'failed', 'Ziel gesperrt'],
            'Weiterleitung mit Zugangsdaten' => ['/go/{b64:http://user:pass@127.0.0.1}/{path}?token={query}', [], 'failed', 'Ungültige Weiterleitung'],
            'Weiterleitung auf file://' => ['/go/{b64:file:///etc/passwd}/{path}?token={query}', [], 'failed', 'Ungültige Weiterleitung'],
        ];
    }

    /**
     * @param array<string, mixed> $http
     */
    #[DataProvider('failureScenarios')]
    public function testErrorPathsNeverLeak(string $template, array $http, string $status, string $noteStart): void
    {
        $s = $this->newSecrets();
        $serverSecret = self::token('server');
        $server = $this->server($serverSecret);
        $this->releaseLoopback($server->port);
        $this->setStorage('on');
        $path = (string) preg_replace_callback('/\{b64:([^}]+)\}/', static fn (array $m): string => rtrim(strtr(base64_encode($m[1]), '+/', '-_'), '='), $template);
        $path = str_replace(['{path}', '{query}'], [$s['path'], $s['query']], $path);
        $jobId = $this->createJob(self::jobBody(self::request($s, $server->url($path)), ['method' => 'POST'] + $http));

        $run = $this->enqueue($jobId);
        $this->schedulerProcess();

        $row = $this->runRow($run);
        self::assertSame($status, $row['status'], (string) $row['note']);
        self::assertStringContainsString($noteStart, (string) $row['note']);
        $this->sweepApi([$jobId], [$run]);
        $this->assertNoLeak([...array_values($s), $serverSecret], $template);
    }

    public function testConnectionRefusedAndUnresolvableNameAndRemovedReleaseNeverLeak(): void
    {
        $s = $this->newSecrets();
        $closed = \Meridian\Tests\Support\LocalHttpServer::freePort();
        $releaseClosed = $this->releaseLoopback($closed);

        // Verbindung abgelehnt (niemand lauscht).
        $refused = $this->createJob(self::jobBody(self::request($s, 'http://127.0.0.1:' . $closed . '/echo/' . $s['path'] . '?token=' . $s['query']), ['method' => 'POST']));
        // Name ohne Eintrag im DNS (echter Resolver des Prozesses).
        $dns = $this->createJob(self::jobBody(self::request($s, 'http://leak-suite-unresolvable.invalid/echo/' . $s['path'] . '?token=' . $s['query']), ['method' => 'POST']), 'admin');
        // Freigabe war beim Speichern da und ist beim Lauf weg: der Lauf prüft neu.
        $dropped = $this->createJob(self::jobBody(self::request($s, 'http://127.0.0.1:' . $closed . '/echo/' . $s['path'] . '?token=' . $s['query']), ['method' => 'POST']));
        $this->removeRelease($releaseClosed);

        $runs = [];
        foreach ([$refused, $dns, $dropped] as $jobId) {
            $runs[$jobId] = $this->enqueue($jobId);
        }
        $this->schedulerProcess();

        // Alle drei Jobs sind gesperrt (Freigabe weg) bzw. nicht auflösbar; die Meldungen sind feste Texte.
        self::assertStringStartsWith('Ziel gesperrt', (string) $this->runRow($runs[$refused])['note']);
        self::assertStringStartsWith('Ziel gesperrt', (string) $this->runRow($runs[$dropped])['note']);
        self::assertStringStartsWith('Ziel nicht auflösbar', (string) $this->runRow($runs[$dns])['note']);

        // Jetzt mit Freigabe, aber ohne lauschenden Server: Verbindung abgelehnt.
        $this->releaseLoopback($closed);
        $retry = $this->enqueue($refused);
        $this->schedulerProcess();
        self::assertStringStartsWith('Verbindung fehlgeschlagen', (string) $this->runRow($retry)['note']);

        $this->sweepApi(array_keys($runs), [...array_values($runs), $retry]);
        $this->assertNoLeak(array_values($s), 'Verbindungsfehler');
    }

    public function testUnreadablePayloadAndUnknownFormatGiveFixedNotesWithoutContent(): void
    {
        $s = $this->newSecrets();
        $server = $this->server();
        $this->releaseLoopback($server->port);
        $url = $server->url('/echo/' . $s['path'] . '?token=' . $s['query']);
        $wrongKey = $this->createJob(self::jobBody(self::request($s, $url), ['method' => 'POST']));
        $unknownVersion = $this->createJob(self::jobBody(self::request($s, $url), ['method' => 'POST']));
        $garbage = $this->createJob(self::jobBody(self::request($s, $url), ['method' => 'POST']));
        $badConfig = $this->createJob(self::jobBody(self::request($s, $url), ['method' => 'POST']));

        // Schlüssel gewechselt: Anfrage mit einem anderen Schlüssel verschlüsselt.
        $other = new \Meridian\Security\SecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $payload = (new \Meridian\Runner\Http\HttpPayload($url, [['Authorization', 'Bearer ' . $s['header']]], null))->toJson();
        $this->db()->execute('UPDATE jobs SET payload_enc = :p WHERE id = :id', ['p' => $other->encrypt($payload), 'id' => $wrongKey]);
        // Format v2 mit den Geheimnissen im Klartext der entschlüsselten Anfrage.
        $v2 = json_encode(['v' => 2, 'url' => $url, 'headers' => [['Authorization', 'Bearer ' . $s['header']]], 'body' => $s['body']], JSON_THROW_ON_ERROR);
        $this->db()->execute('UPDATE jobs SET payload_enc = :p WHERE id = :id', ['p' => $this->box()->encrypt($v2), 'id' => $unknownVersion]);
        // Beschädigter Chiffretext.
        $this->db()->execute('UPDATE jobs SET payload_enc = :p WHERE id = :id', ['p' => 'v1:' . base64_encode(random_bytes(48)), 'id' => $garbage]);
        // config_json mit unbekannter Version.
        $this->db()->execute("UPDATE jobs SET config_json = :c WHERE id = :id", ['c' => '{"v":9}', 'id' => $badConfig]);

        $runs = [];
        foreach ([$wrongKey, $unknownVersion, $garbage, $badConfig] as $jobId) {
            $runs[] = $this->enqueue($jobId);
        }
        $this->schedulerProcess();

        foreach ($runs as $runId) {
            $row = $this->runRow($runId);
            self::assertSame('failed', $row['status']);
            self::assertNotNull($row['note']);
            self::assertLessThan(200, strlen($row['note']), 'feste kurze Meldung');
        }
        self::assertSame(0, $server->hitCount(), 'nichts wurde gesendet');
        $this->sweepApi([$wrongKey, $unknownVersion, $garbage, $badConfig], $runs);
        $this->assertNoLeak(array_values($s), 'unlesbare Anfrage');
    }
}
