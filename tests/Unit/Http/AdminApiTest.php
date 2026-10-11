<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Http;

use Meridian\Auth\LoginThrottle;
use Meridian\Tests\Support\ApiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminApiTest extends TestCase
{
    use ApiHarness;

    private const ORIGIN = 'https://meridian.test';

    /**
     * @return array{cookie: string, csrf: string}
     */
    private function sessionFor(string $username, string $role): array
    {
        $this->users()->create($username, ucfirst($username), self::PASSWORD, $role);
        $login = $this->login($username);

        return ['cookie' => $this->sessionCookie($login), 'csrf' => $this->csrfOf($login)];
    }

    /**
     * @param array{cookie: string, csrf: string} $session
     */
    private function audit(array $session, string $query = ''): Response
    {
        return $this->call('GET', '/api/audit' . $query, [], [], [self::COOKIE => $session['cookie']]);
    }

    /**
     * @param array{cookie: string, csrf: string} $session
     * @param array<string, mixed>                $body
     */
    private function unlock(array $session, array $body, bool $withCsrf = true): Response
    {
        $headers = ['Origin' => self::ORIGIN] + ($withCsrf ? ['X-CSRF-Token' => $session['csrf']] : []);

        return $this->call('POST', '/api/users/unlock', $body, $headers, [self::COOKIE => $session['cookie']]);
    }

    private function seedAudit(int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->db()->execute(
                'INSERT INTO audit_log (user_id, action, target, created_at) VALUES (NULL, :a, :t, :c)',
                ['a' => $i % 2 === 0 ? 'test.even' : 'test.odd', 't' => 'eintrag-' . $i, 'c' => '2026-10-07T08:00:00+00:00'],
            );
        }
    }

    // --- Audit-Log lesen: Rechte -------------------------------------------------------------------

    public function testAuditLogNeedsLogin(): void
    {
        self::assertSame(401, $this->call('GET', '/api/audit')->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/audit', [], [], [self::COOKIE => 'gefaelscht'])->getStatusCode());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function rolesForAuditLog(): array
    {
        return ['Admin' => ['Admin', 200], 'Operator' => ['Operator', 403], 'Beobachter' => ['Beobachter', 403]];
    }

    #[DataProvider('rolesForAuditLog')]
    public function testAuditLogIsOnlyForUserManagers(string $role, int $expected): void
    {
        $session = $this->sessionFor('person', $role);

        $response = $this->audit($session);

        self::assertSame($expected, $response->getStatusCode());
        if ($expected === 403) {
            self::assertNull(self::dig($this->json($response), 'entries'), 'Keine Daten bei verweigertem Zugriff.');
        }
    }

    public function testAdminLimitedToACategoryDoesNotGetUserManagement(): void
    {
        $id = $this->users()->create('begrenzt', 'Begrenzt', self::PASSWORD, 'Admin');
        $this->db()->execute("INSERT INTO categories (id, name) VALUES (1, 'A')");
        $grant = self::dig($this->db()->fetchOne('SELECT id FROM user_roles WHERE user_id = :u', ['u' => $id]), 'id');
        self::assertIsInt($grant);
        $this->db()->execute('UPDATE user_roles SET all_categories = 0 WHERE id = :g', ['g' => $grant]);
        $this->db()->execute('INSERT INTO user_role_categories (user_role_id, category_id) VALUES (:g, 1)', ['g' => $grant]);
        $login = $this->login('begrenzt');
        $session = ['cookie' => $this->sessionCookie($login), 'csrf' => $this->csrfOf($login)];

        self::assertSame(403, $this->audit($session)->getStatusCode());
        self::assertSame(403, $this->unlock($session, ['username' => 'jemand'])->getStatusCode());
    }

    public function testDeactivatedAdminLosesAccessAtOnce(): void
    {
        $session = $this->sessionFor('alex', 'Admin');
        self::assertSame(200, $this->audit($session)->getStatusCode());

        $this->db()->execute("UPDATE users SET is_active = 0 WHERE username = 'alex'");

        self::assertSame(401, $this->audit($session)->getStatusCode());
    }

    // --- Audit-Log lesen: Inhalt ---------------------------------------------------------------------

    public function testNewestFirstWithCursorAndNoStoreHeader(): void
    {
        $session = $this->sessionFor('alex', 'Admin');
        $this->seedAudit(5);

        $first = $this->audit($session, '?limit=3');
        $data = $this->json($first);
        self::assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));
        $entries = self::dig($data, 'entries');
        self::assertIsArray($entries);
        self::assertCount(3, $entries);
        $ids = array_column($entries, 'id');
        $sorted = $ids;
        rsort($sorted);
        self::assertSame($sorted, $ids, 'neueste zuerst');
        $cursor = self::dig($data, 'next_before_id');
        self::assertIsInt($cursor);
        self::assertSame(end($ids), $cursor);

        $second = $this->json($this->audit($session, '?limit=100&before_id=' . $cursor));
        $older = self::dig($second, 'entries');
        self::assertIsArray($older);
        foreach ($older as $entry) {
            self::assertLessThan($cursor, self::dig($entry, 'id'));
        }
        self::assertNull(self::dig($second, 'next_before_id'), 'letzte Seite hat keinen Cursor');
    }

    public function testActionFilterAndUsernameJoin(): void
    {
        $session = $this->sessionFor('alex', 'Admin');
        $this->seedAudit(6);

        $data = $this->json($this->audit($session, '?action=test.even&limit=50'));
        $entries = self::dig($data, 'entries');
        self::assertIsArray($entries);
        self::assertCount(3, $entries);
        foreach ($entries as $entry) {
            self::assertSame('test.even', self::dig($entry, 'action'));
        }

        $logins = self::dig($this->json($this->audit($session, '?action=auth.login')), 'entries');
        self::assertIsArray($logins);
        self::assertSame('alex', self::dig($logins, 0, 'username'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badQueries(): array
    {
        return [
            'limit null' => ['?limit=0'],
            'limit zu gross' => ['?limit=201'],
            'limit keine Zahl' => ['?limit=abc'],
            'limit negativ' => ['?limit=-5'],
            'limit als Array' => ['?limit[]=1'],
            'before_id null' => ['?before_id=0'],
            'before_id Array' => ['?before_id[a]=1'],
            'action mit Leerzeichen' => ['?action=a%20b'],
            'action mit SQL' => ['?action=x%27%20OR%201%3D1--'],
            'action zu lang' => ['?action=' . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
            'action als Array' => ['?action[]=x'],
        ];
    }

    #[DataProvider('badQueries')]
    public function testInvalidQueriesAreRejectedNotTrimmed(string $query): void
    {
        $session = $this->sessionFor('alex', 'Admin');

        $response = $this->audit($session, $query);

        self::assertSame(400, $response->getStatusCode());
        self::assertNull(self::dig($this->json($response), 'entries'));
    }

    public function testEntriesAreMaskedOnTheWayOut(): void
    {
        $session = $this->sessionFor('alex', 'Admin');
        $this->db()->execute(
            'INSERT INTO audit_log (user_id, action, target, created_at) VALUES (NULL, :a, :t, :c)',
            ['a' => 'test.leak', 't' => 'https://example.test/cron?key=TEST-SECRET-do-not-use-123', 'c' => '2026-10-07T08:00:00+00:00'],
        );

        $response = $this->audit($session, '?action=test.leak');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('TEST-SECRET-do-not-use-123', (string) $response->getContent());
    }

    // --- Entsperren --------------------------------------------------------------------------------------

    public function testUnlockNeedsLoginRightAndCsrf(): void
    {
        $this->users()->create('opfer', 'Opfer', self::PASSWORD, 'Operator');
        $admin = $this->sessionFor('alex', 'Admin');
        $operator = $this->sessionFor('olaf', 'Operator');
        $viewer = $this->sessionFor('vera', 'Beobachter');

        self::assertSame(401, $this->call('POST', '/api/users/unlock', ['username' => 'opfer'], ['Origin' => self::ORIGIN, 'X-CSRF-Token' => $admin['csrf']])->getStatusCode());
        self::assertSame(403, $this->unlock($operator, ['username' => 'opfer'])->getStatusCode());
        self::assertSame(403, $this->unlock($viewer, ['username' => 'opfer'])->getStatusCode());
        self::assertSame(403, $this->unlock($admin, ['username' => 'opfer'], false)->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/users/unlock', ['username' => 'opfer'], ['Origin' => 'https://evil.test', 'X-CSRF-Token' => $admin['csrf']], [self::COOKIE => $admin['cookie']])->getStatusCode());
        self::assertSame(405, $this->call('GET', '/api/users/unlock', [], [], [self::COOKIE => $admin['cookie']])->getStatusCode());
    }

    public function testUnlockFreesALockedAccountAndIsAudited(): void
    {
        $this->users()->create('opfer', 'Opfer', self::PASSWORD, 'Operator');
        $admin = $this->sessionFor('alex', 'Admin');
        for ($i = 0; $i < 5; ++$i) {
            $this->login('opfer', 'TEST-WRONG-do-not-use-1', '10.0.0.' . $i);
        }
        self::assertSame(429, $this->login('opfer', self::PASSWORD, '10.0.1.1')->getStatusCode());

        self::assertSame(200, $this->unlock($admin, ['username' => 'opfer'])->getStatusCode());

        self::assertSame(200, $this->login('opfer', self::PASSWORD, '10.0.1.1')->getStatusCode());
        $entries = self::dig($this->json($this->audit($admin, '?action=auth.unlocked')), 'entries');
        self::assertIsArray($entries);
        self::assertSame('opfer', self::dig($entries, 0, 'target'));
        self::assertSame('alex', self::dig($entries, 0, 'username'));
    }

    public function testUnlockOfAUserDoesNotFreeTheLockedIpAddressButUnlockOfTheIpDoes(): void
    {
        $this->users()->create('opfer', 'Opfer', self::PASSWORD, 'Operator');
        $admin = $this->sessionFor('alex', 'Admin');
        $throttle = new LoginThrottle($this->db(), $this->clock());
        for ($i = 0; $i < 50; ++$i) {
            $throttle->recordFailure('x' . $i, '198.51.100.7');
        }

        $this->unlock($admin, ['username' => 'opfer']);
        self::assertSame(429, $this->login('opfer', self::PASSWORD, '198.51.100.7')->getStatusCode());

        self::assertSame(200, $this->unlock($admin, ['ip' => '198.51.100.7'])->getStatusCode());
        self::assertSame(200, $this->login('opfer', self::PASSWORD, '198.51.100.7')->getStatusCode());
        $entries = self::dig($this->json($this->audit($admin, '?action=auth.unlocked')), 'entries');
        self::assertIsArray($entries);
        self::assertSame('ip:198.51.100.7', self::dig($entries, 0, 'target'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function badUnlockBodies(): array
    {
        return [
            'leer' => [[]],
            'kein String' => [['username' => ['a']]],
            'Leerzeichen' => [['username' => 'a b']],
            'zu kurz' => [['username' => 'a']],
            'zu lang' => [['username' => str_repeat('a', 65)]],
            'Sonderzeichen' => [['username' => "x'; DROP TABLE users;--"]],
            'weder noch' => [['foo' => 'bar']],
            'beides' => [['username' => 'alex', 'ip' => '10.0.0.1']],
            'keine IP' => [['ip' => 'nicht-eine-ip']],
            'IP als Array' => [['ip' => ['10.0.0.1']]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('badUnlockBodies')]
    public function testUnlockRejectsBadUsernames(array $body): void
    {
        $admin = $this->sessionFor('alex', 'Admin');

        self::assertSame(400, $this->unlock($admin, $body)->getStatusCode());
        self::assertNull($this->db()->fetchOne("SELECT 1 AS x FROM audit_log WHERE action = 'auth.unlocked'"));
    }
}
