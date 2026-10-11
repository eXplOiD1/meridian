<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Network;

use Meridian\Job\HttpJobConfig;
use Meridian\Job\HttpMethod;
use Meridian\Job\StoreResponse;
use Meridian\Runner\Http\AddressPolicy;
use Meridian\Runner\Http\DbHttpJobSource;
use Meridian\Runner\Http\DbInternalTargetSource;
use Meridian\Runner\Http\HttpPayload;
use Meridian\Runner\Http\HttpRunner;
use Meridian\Runner\Http\TargetGuard;
use Meridian\Runner\JobType;
use Meridian\Runner\RunRequest;
use Meridian\Schedule\RunStatus;
use Meridian\Schedule\RunTrigger;
use Meridian\Security\SecretMasker;
use Meridian\Tests\Support\ApiHarness;
use Meridian\Tests\Support\CountingHeartbeat;
use Meridian\Tests\Support\FakeHostResolver;
use Meridian\Tests\Support\FakeRunSettings;
use Meridian\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Freigaben interner Ziele über die API (docs/decisions/0003, E5, §4.2, §7.2): nur `network.internal_targets`
 * uneingeschränkt, CSRF, Prüfung wie beim Laden, Audit, Wirkung beim nächsten Lauf ohne Neustart.
 */
final class InternalTargetApiTest extends TestCase
{
    use ApiHarness;

    private const ORIGIN = 'https://meridian.test';
    private const PATH = '/api/settings/internal-targets';

    /**
     * @param list<string>|null $categories null = alle Kategorien
     *
     * @return array{cookie: string, csrf: string}
     */
    private function sessionFor(string $username, string $role, ?array $categories = null): array
    {
        $id = $this->users()->create($username, ucfirst($username), self::PASSWORD, $role);
        if ($categories !== null) {
            $grant = self::dig($this->db()->fetchOne('SELECT id FROM user_roles WHERE user_id = :u', ['u' => $id]), 'id');
            self::assertIsInt($grant);
            $this->db()->execute('UPDATE user_roles SET all_categories = 0 WHERE id = :g', ['g' => $grant]);
            foreach ($categories as $name) {
                $this->db()->execute('INSERT INTO user_role_categories (user_role_id, category_id) SELECT :g, id FROM categories WHERE name = :n', ['g' => $grant, 'n' => $name]);
            }
        }
        $login = $this->login($username);

        return ['cookie' => $this->sessionCookie($login), 'csrf' => $this->csrfOf($login)];
    }

    private function categories(): void
    {
        $this->db()->execute("INSERT INTO categories (id, name) VALUES (1, 'A'), (2, 'B')");
    }

    /**
     * @param array{cookie: string, csrf: string}|null $session
     * @param array<string, mixed>                     $body
     */
    private function send(string $method, string $path, ?array $session, array $body = [], bool $withCsrf = true): Response
    {
        $headers = $method === 'GET' ? [] : ['Origin' => self::ORIGIN] + ($withCsrf && $session !== null ? ['X-CSRF-Token' => $session['csrf']] : []);

        return $this->call($method, $path, $body, $headers, $session === null ? [] : [self::COOKIE => $session['cookie']]);
    }

    private function targetCount(): int
    {
        $n = self::dig($this->db()->fetchOne('SELECT COUNT(*) AS n FROM http_internal_targets'), 'n');
        self::assertIsInt($n);

        return $n;
    }

    /**
     * @return list<array{action: string, target: string|null, user_id: int|null}>
     */
    private function auditEntries(): array
    {
        $out = [];
        foreach ($this->db()->fetchAll("SELECT action, target, user_id FROM audit_log WHERE action LIKE 'network.%' ORDER BY id") as $row) {
            $out[] = [
                'action' => is_string($row['action']) ? $row['action'] : '',
                'target' => is_string($row['target']) ? $row['target'] : null,
                'user_id' => is_int($row['user_id']) ? $row['user_id'] : null,
            ];
        }

        return $out;
    }

    // ---- Rechte ----

    public function testAnonymousGets401(): void
    {
        self::assertSame(401, $this->send('GET', self::PATH, null)->getStatusCode());
        self::assertSame(401, $this->send('POST', self::PATH, null, ['kind' => 'cidr', 'value' => '10.0.0.0/8'])->getStatusCode());
        self::assertSame(401, $this->send('DELETE', self::PATH . '/1', null)->getStatusCode());
        self::assertSame(0, $this->targetCount());
    }

    /**
     * @return array<string, array{0: string, 1: list<string>|null, 2: bool}>
     */
    public static function roles(): array
    {
        return [
            'Admin' => ['Admin', null, true],
            'Admin auf Kategorie A beschränkt' => ['Admin', ['A'], false],
            'Operator' => ['Operator', null, false],
            'Operator auf A' => ['Operator', ['A'], false],
            'Beobachter' => ['Beobachter', null, false],
        ];
    }

    /**
     * @param list<string>|null $categories
     */
    #[DataProvider('roles')]
    public function testOnlyUnrestrictedHoldersOfThePermission(string $role, ?array $categories, bool $allowed): void
    {
        $this->categories();
        $this->db()->execute("INSERT INTO http_internal_targets (id, kind, value, port, created_at) VALUES (7, 'cidr', '10.9.0.0/16', 0, '2026-10-07T00:00:00+00:00')");
        $session = $this->sessionFor('person', $role, $categories);

        $list = $this->send('GET', self::PATH, $session);
        $add = $this->send('POST', self::PATH, $session, ['kind' => 'cidr', 'value' => '192.168.1.0/24', 'port' => 0, 'category_id' => 1, 'note' => 'NAS']);
        $remove = $this->send('DELETE', self::PATH . '/7', $session);

        if ($allowed) {
            self::assertSame([200, 201, 204], [$list->getStatusCode(), $add->getStatusCode(), $remove->getStatusCode()]);
            self::assertSame(1, $this->targetCount());

            return;
        }
        self::assertSame([403, 403, 403], [$list->getStatusCode(), $add->getStatusCode(), $remove->getStatusCode()]);
        self::assertNull(self::dig($this->json($list), 'targets'), 'keine Daten bei verweigertem Zugriff');
        self::assertSame(1, $this->targetCount(), 'nichts geändert');
        self::assertSame([], $this->auditEntries());
    }

    public function testChangesNeedCsrfAndOrigin(): void
    {
        $this->db()->execute("INSERT INTO http_internal_targets (id, kind, value, port, created_at) VALUES (7, 'cidr', '10.9.0.0/16', 0, '2026-10-07T00:00:00+00:00')");
        $admin = $this->sessionFor('alex', 'Admin');

        self::assertSame(403, $this->send('POST', self::PATH, $admin, ['kind' => 'cidr', 'value' => '10.0.0.0/8'], false)->getStatusCode());
        self::assertSame(403, $this->send('DELETE', self::PATH . '/7', $admin, [], false)->getStatusCode());
        $foreign = $this->call('POST', self::PATH, ['kind' => 'cidr', 'value' => '10.0.0.0/8'], ['Origin' => 'https://evil.example', 'X-CSRF-Token' => $admin['csrf']], [self::COOKIE => $admin['cookie']]);
        self::assertSame(403, $foreign->getStatusCode());
        self::assertSame(1, $this->targetCount());
    }

    // ---- Anlegen, Liste, Löschen, Audit ----

    public function testAddListRemoveWithAudit(): void
    {
        $this->categories();
        $admin = $this->sessionFor('alex', 'Admin');

        $cidr = $this->send('POST', self::PATH, $admin, ['kind' => 'cidr', 'value' => '192.168.1.0/24', 'port' => 8080, 'category_id' => 1, 'note' => 'NAS im Keller']);
        $host = $this->send('POST', self::PATH, $admin, ['kind' => 'host', 'value' => 'nextcloud', 'port' => 0, 'category_id' => null]);
        $loop = $this->send('POST', self::PATH, $admin, ['kind' => 'cidr', 'value' => '127.0.0.1/32', 'port' => 8099]);

        self::assertSame([201, 201, 201], [$cidr->getStatusCode(), $host->getStatusCode(), $loop->getStatusCode()]);
        self::assertStringContainsString('no-store', (string) $cidr->headers->get('Cache-Control'));
        self::assertSame(
            ['kind' => 'cidr', 'value' => '192.168.1.0/24', 'port' => 8080, 'category' => ['id' => 1, 'name' => 'A'], 'note' => 'NAS im Keller', 'created_by' => ['display_name' => 'Alex']],
            array_intersect_key((array) self::dig($this->json($cidr), 'target'), array_flip(['kind', 'value', 'port', 'category', 'note', 'created_by'])),
        );

        $list = $this->json($this->send('GET', self::PATH, $admin));
        $targets = self::dig($list, 'targets');
        self::assertIsArray($targets);
        self::assertCount(3, $targets);
        self::assertSame(['192.168.1.0/24', 'nextcloud', '127.0.0.1/32'], array_column($targets, 'value'));
        self::assertNull(self::dig($targets, 1, 'category'));

        $id = self::dig($this->json($host), 'target', 'id');
        self::assertIsInt($id);
        self::assertSame(204, $this->send('DELETE', self::PATH . '/' . $id, $admin)->getStatusCode());
        self::assertSame(404, $this->send('DELETE', self::PATH . '/' . $id, $admin)->getStatusCode(), 'schon gelöscht');
        self::assertSame(404, $this->send('DELETE', self::PATH . '/999', $admin)->getStatusCode());
        self::assertSame(404, $this->send('DELETE', self::PATH . '/abc', $admin)->getStatusCode());

        $userId = self::dig($this->db()->fetchOne("SELECT id FROM users WHERE username = 'alex'"), 'id');
        self::assertSame([
            ['action' => 'network.internal_target_added', 'target' => 'cidr 192.168.1.0/24:8080 (Kategorie A)', 'user_id' => $userId],
            ['action' => 'network.internal_target_added', 'target' => 'host nextcloud:* (global)', 'user_id' => $userId],
            ['action' => 'network.internal_target_added', 'target' => 'cidr 127.0.0.1/32:8099 (global)', 'user_id' => $userId],
            ['action' => 'network.internal_target_removed', 'target' => 'host nextcloud:* (global)', 'user_id' => $userId],
        ], $this->auditEntries());
    }

    /**
     * Nie freigebbare Netze und Namen, Hostbits, ungültige Eingaben (§3.2, §7.2): 422, nichts gespeichert.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'Loopback-Netz' => [['kind' => 'cidr', 'value' => '127.0.0.0/8'], 'value'];
        yield 'Loopback ohne Port' => [['kind' => 'cidr', 'value' => '127.0.0.1/32', 'port' => 0], 'port'];
        yield 'Link-local/Metadaten' => [['kind' => 'cidr', 'value' => '169.254.0.0/16'], 'value'];
        yield 'Metadaten einzeln' => [['kind' => 'cidr', 'value' => '169.254.169.254/32', 'port' => 80], 'value'];
        yield 'alles' => [['kind' => 'cidr', 'value' => '0.0.0.0/0'], 'value'];
        yield '0.0.0.0/8' => [['kind' => 'cidr', 'value' => '0.0.0.0/8'], 'value'];
        yield 'Multicast' => [['kind' => 'cidr', 'value' => '224.0.0.0/4'], 'value'];
        yield 'Dokumentation' => [['kind' => 'cidr', 'value' => '192.0.2.0/24'], 'value'];
        yield 'IPv6 Link-local' => [['kind' => 'cidr', 'value' => 'fe80::/10'], 'value'];
        yield 'IPv4-gemappt' => [['kind' => 'cidr', 'value' => '::ffff:10.0.0.0/104'], 'value'];
        yield 'öffentlich' => [['kind' => 'cidr', 'value' => '93.184.0.0/16'], 'value'];
        yield 'Hostbits gesetzt' => [['kind' => 'cidr', 'value' => '192.168.1.5/24'], 'value'];
        yield 'zu groß' => [['kind' => 'cidr', 'value' => '10.0.0.0/7'], 'value'];
        yield 'nicht normalisiert' => [['kind' => 'cidr', 'value' => 'FD12::/16'], 'value'];
        yield 'localhost' => [['kind' => 'host', 'value' => 'localhost'], 'value'];
        yield '*.localhost' => [['kind' => 'host', 'value' => 'app.localhost'], 'value'];
        yield 'Metadaten-Name' => [['kind' => 'host', 'value' => 'metadata.google.internal'], 'value'];
        yield 'IP als Host' => [['kind' => 'host', 'value' => '10.0.0.1'], 'value'];
        yield 'Host groß' => [['kind' => 'host', 'value' => 'NextCloud'], 'value'];
        yield 'Host mit Port' => [['kind' => 'host', 'value' => 'nextcloud:80'], 'value'];
        yield 'Art unbekannt' => [['kind' => 'url', 'value' => '10.0.0.0/8'], 'kind'];
        yield 'Port als Text' => [['kind' => 'cidr', 'value' => '10.0.0.0/8', 'port' => '8080'], 'port'];
        yield 'Port zu groß' => [['kind' => 'cidr', 'value' => '10.0.0.0/8', 'port' => 65536], 'port'];
        yield 'Kategorie unbekannt' => [['kind' => 'cidr', 'value' => '10.0.0.0/8', 'category_id' => 99], 'category_id'];
        yield 'Kategorie als Name' => [['kind' => 'cidr', 'value' => '10.0.0.0/8', 'category_id' => 'A'], 'category_id'];
        yield 'Notiz zu lang' => [['kind' => 'cidr', 'value' => '10.0.0.0/8', 'note' => str_repeat('ä', 201)], 'note'];
        yield 'Notiz mit Zeilenumbruch' => [['kind' => 'cidr', 'value' => '10.0.0.0/8', 'note' => "a\nb"], 'note'];
        yield 'unbekanntes Feld' => [['kind' => 'cidr', 'value' => '10.0.0.0/8', 'all' => true], 'body'];
        yield 'Wert fehlt' => [['kind' => 'cidr'], 'value'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidInputs')]
    public function testInvalidOrNeverReleasableInputIs422(array $body, string $field): void
    {
        $this->categories();
        $admin = $this->sessionFor('alex', 'Admin');

        $response = $this->send('POST', self::PATH, $admin, $body);

        self::assertSame(422, $response->getStatusCode());
        $fields = self::dig($this->json($response), 'fields');
        self::assertIsArray($fields);
        self::assertArrayHasKey($field, $fields);
        self::assertSame(0, $this->targetCount());
        self::assertSame([], $this->auditEntries());
        $value = $body['value'] ?? null;
        // Netze und Hostnamen erscheinen nie in der Meldung (feste Texte nennen nur „localhost“ als Beispiel).
        if (is_string($value) && strlen($value) > 3 && $value !== 'localhost') {
            self::assertStringNotContainsString($value, (string) $response->getContent(), 'Meldung ohne Eingabewert');
        }
    }

    public function testDuplicateIsRefusedButSameValueInOtherScopeIsAllowed(): void
    {
        $this->categories();
        $admin = $this->sessionFor('alex', 'Admin');
        $body = ['kind' => 'cidr', 'value' => '10.0.0.0/8', 'port' => 0, 'category_id' => null];

        self::assertSame(201, $this->send('POST', self::PATH, $admin, $body)->getStatusCode());
        self::assertSame(422, $this->send('POST', self::PATH, $admin, $body)->getStatusCode());
        self::assertSame(201, $this->send('POST', self::PATH, $admin, ['category_id' => 1] + $body)->getStatusCode());
        self::assertSame(201, $this->send('POST', self::PATH, $admin, ['port' => 443] + $body)->getStatusCode());
        self::assertSame(3, $this->targetCount());
    }

    public function testOutputIsMasked(): void
    {
        $admin = $this->sessionFor('alex', 'Admin');
        $note = 'Zugang password=TEST-SECRET-do-not-use-123';

        $response = $this->send('POST', self::PATH, $admin, ['kind' => 'host', 'value' => 'nextcloud', 'note' => $note]);
        $list = $this->send('GET', self::PATH, $admin);

        self::assertSame(201, $response->getStatusCode());
        foreach ([$response, $list] as $r) {
            self::assertStringNotContainsString('TEST-SECRET-do-not-use-123', (string) $r->getContent());
        }
    }

    // ---- Wirkung beim nächsten Lauf (§7.2 Ende-zu-Ende) ----

    public function testReleaseTakesEffectOnTheNextRunWithoutRestart(): void
    {
        $this->categories();
        $admin = $this->sessionFor('alex', 'Admin');
        $dns = new FakeHostResolver();
        $dns->set('nas.example.invalid', ['192.168.1.5']);
        $dns->set('db.example.invalid', ['192.168.1.6']);
        $transport = new FakeTransport(FakeTransport::response(200));
        $runner = new HttpRunner(
            new DbHttpJobSource($this->db()),
            $this->box(),
            new FakeRunSettings(),
            new TargetGuard($dns, new AddressPolicy(new DbInternalTargetSource($this->db()))),
            $transport,
        );
        $jobA = $this->httpJob('http://nas.example.invalid/', 1);
        $jobB = $this->httpJob('http://nas.example.invalid/', 2);
        $run = fn (int $job): RunStatus => $runner->run(new RunRequest(1, $job, JobType::Http, RunTrigger::Schedule, 1), new CountingHeartbeat(), new SecretMasker(), new \Meridian\Runner\NullLiveLog())->status;

        self::assertSame(RunStatus::Failed, $run($jobA), 'vor der Freigabe gesperrt');

        $added = $this->send('POST', self::PATH, $admin, ['kind' => 'cidr', 'value' => '192.168.1.0/24', 'port' => 80, 'category_id' => 1]);
        self::assertSame(201, $added->getStatusCode());
        self::assertSame(RunStatus::Ok, $run($jobA), 'Freigabe gilt sofort');
        self::assertSame(RunStatus::Failed, $run($jobB), 'nur für Kategorie A');

        $id = self::dig($this->json($added), 'target', 'id');
        self::assertIsInt($id);
        self::assertSame(204, $this->send('DELETE', self::PATH . '/' . $id, $admin)->getStatusCode());
        self::assertSame(RunStatus::Failed, $run($jobA), 'nach dem Löschen wieder gesperrt');
        self::assertCount(1, $transport->requests);
    }

    private function httpJob(string $url, int $category): int
    {
        $parsed = (new HttpPayload($url))->url();
        $config = new HttpJobConfig(HttpMethod::Get, 30, [[200, 299]], 0, StoreResponse::Inherit, $parsed->scheme, $parsed->host, $parsed->port, $parsed->origin() . '/', false, 0, false);
        $this->db()->execute(
            "INSERT INTO jobs (name, type, category_id, cron, config_json, payload_enc, created_at, updated_at) VALUES ('Job', 'http', :c, '* * * * *', :config, :payload, '2026-10-07T00:00:00+00:00', '2026-10-07T00:00:00+00:00')",
            ['c' => $category, 'config' => $config->toJson(), 'payload' => $this->box()->encrypt((new HttpPayload($url))->toJson())],
        );

        return $this->db()->lastInsertId();
    }
}
