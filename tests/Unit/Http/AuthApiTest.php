<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Http;

use Meridian\Auth\LoginThrottle;
use Meridian\Auth\SessionManager;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\Tests\Support\ApiHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class AuthApiTest extends TestCase
{
    use ApiHarness;

    // --- Anmeldung -------------------------------------------------------------------------

    public function testLoginSetsHardenedCookieAndNeverStoresTheToken(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $response = $this->login('alex');

        self::assertSame(200, $response->getStatusCode());
        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        $cookie = $cookies[0];
        self::assertSame(self::COOKIE, $cookie->getName());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain());

        $token = (string) $cookie->getValue();
        $row = $this->db()->fetchOne('SELECT token_hash FROM sessions');
        self::assertSame(hash('sha256', $token), $row['token_hash'] ?? null);
        self::assertNotSame($token, $row['token_hash'] ?? null);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testPlainHttpGetsCookieWithoutSecureAndPrefixAndStillWorks(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $login = $this->callPlainHttp('POST', '/api/auth/login', ['username' => 'alex', 'password' => self::PASSWORD]);
        self::assertSame(200, $login->getStatusCode());

        $cookie = $login->headers->getCookies()[0];
        self::assertSame('meridian_session', $cookie->getName());
        self::assertFalse($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', $cookie->getSameSite());

        $me = $this->callPlainHttp('GET', '/api/auth/me', [], [$cookie->getName() => (string) $cookie->getValue()]);
        self::assertSame(200, $me->getStatusCode());
    }

    public function testCookieNameMustMatchTheTransport(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $secureCookie = $this->sessionCookie($this->login('alex'));

        self::assertSame(401, $this->callPlainHttp('GET', '/api/auth/me', [], [self::COOKIE => $secureCookie])->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/auth/me', [], [], ['meridian_session' => $secureCookie])->getStatusCode());
    }

    public function testEveryLoginGetsANewSessionId(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');

        self::assertNotSame($this->sessionCookie($this->login('alex')), $this->sessionCookie($this->login('alex')));
    }

    public function testWrongPasswordUnknownUserAndInactiveUserLookIdentical(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $this->users()->create('inaktiv', 'Inaktiv', self::PASSWORD, 'Operator');
        $this->db()->execute("UPDATE users SET is_active = 0 WHERE username = 'inaktiv'");

        $wrong = $this->login('alex', 'TEST-WRONG-do-not-use-1');
        $unknown = $this->login('niemand');
        $inactive = $this->login('inaktiv');

        foreach ([$wrong, $unknown, $inactive] as $response) {
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('Benutzername oder Passwort falsch.', $this->json($response)['error'] ?? null);
            self::assertCount(0, $response->headers->getCookies());
        }
        self::assertSame((string) $wrong->getContent(), (string) $unknown->getContent());
        self::assertSame((string) $wrong->getContent(), (string) $inactive->getContent());
    }

    public function testLockoutAfterFiveFailuresAlsoBlocksTheCorrectPasswordUntilItExpires(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(401, $this->login('alex', 'TEST-WRONG-do-not-use-1')->getStatusCode());
        }

        $locked = $this->login('alex');
        self::assertSame(429, $locked->getStatusCode());
        self::assertSame('30', $locked->headers->get('Retry-After'));
        self::assertCount(0, $locked->headers->getCookies());

        $this->clock()->advance(31);
        self::assertSame(200, $this->login('alex')->getStatusCode());
        self::assertSame(200, $this->login('alex')->getStatusCode(), 'Erfolg setzt den Zähler zurück.');
    }

    public function testWhileLockedTheAnswerIsTheSameForTheRightAndTheWrongPassword(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        for ($i = 0; $i < 5; ++$i) {
            $this->login('alex', 'TEST-WRONG-do-not-use-1');
        }

        $right = $this->login('alex', self::PASSWORD);
        $wrong = $this->login('alex', 'TEST-WRONG-do-not-use-1');

        self::assertSame(429, $right->getStatusCode());
        self::assertSame($wrong->getStatusCode(), $right->getStatusCode());
        self::assertSame((string) $wrong->getContent(), (string) $right->getContent(), 'die Antwort verrät das Passwort nicht');
        self::assertSame($wrong->headers->get('Retry-After'), $right->headers->get('Retry-After'));
    }

    public function testLockoutDoesNotRevealWhetherTheUserExists(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->login('ghost', 'TEST-WRONG-do-not-use-1');
        }

        $response = $this->login('ghost');
        self::assertSame(429, $response->getStatusCode());
    }

    public function testClientIpIsLockedAfterManyFailuresAcrossNames(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $throttle = new LoginThrottle($this->db(), $this->clock());
        for ($i = 0; $i < 50; ++$i) {
            $throttle->recordFailure('user' . $i, '198.51.100.7');
        }

        self::assertSame(429, $this->login('alex', self::PASSWORD, '198.51.100.7')->getStatusCode());
        self::assertSame(200, $this->login('alex', self::PASSWORD, '198.51.100.8')->getStatusCode());
    }

    public function testStrictTransportSecurityOnlyInProductionOverHttps(): void
    {
        $prod = $this->call('GET', '/api/health');
        self::assertSame('max-age=31536000', $prod->headers->get('Strict-Transport-Security'));

        $this->kernelInstance = $this->buildKernel('dev');
        self::assertNull($this->call('GET', '/api/health')->headers->get('Strict-Transport-Security'));
    }

    public function testDeletingTheLastCategoryNeverTurnsARestrictedUserIntoAnUnrestrictedOne(): void
    {
        $id = $this->users()->create('begrenzt', 'Begrenzt', self::PASSWORD, 'Operator');
        $this->db()->execute("INSERT INTO categories (id, name) VALUES (1, 'A'), (2, 'B')");
        $grantId = self::dig($this->db()->fetchOne('SELECT id FROM user_roles WHERE user_id = :u', ['u' => $id]), 'id');
        self::assertIsInt($grantId);
        $this->db()->execute('UPDATE user_roles SET all_categories = 0 WHERE id = :g', ['g' => $grantId]);
        $this->db()->execute('INSERT INTO user_role_categories (user_role_id, category_id) VALUES (:g, 1)', ['g' => $grantId]);
        $cookie = $this->sessionCookie($this->login('begrenzt'));

        $this->db()->execute('DELETE FROM categories WHERE id = 1');
        $data = $this->json($this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $cookie]));

        self::assertSame([], self::dig($data, 'roles', 0, 'categories'), 'leer heißt: nichts erlaubt, nie: alles');
        $acl = new AccessControl();
        self::assertFalse($acl->can($this->users()->grantsFor($id), Permission::EditHttpJobs, 'B'));
        self::assertFalse($acl->can($this->users()->grantsFor($id), Permission::ViewJobs, null));
    }

    public function testLoginRequiresMatchingOrigin(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $body = ['username' => 'alex', 'password' => self::PASSWORD];

        self::assertSame(403, $this->call('POST', '/api/auth/login', $body)->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/auth/login', $body, ['Origin' => 'https://evil.test'])->getStatusCode());
        self::assertSame(0, $this->sessionCount());
    }

    public function testMalformedBodiesAreRejected(): void
    {
        $origin = ['Origin' => 'https://meridian.test'];

        self::assertSame(400, $this->call('POST', '/api/auth/login', [], $origin)->getStatusCode());
        self::assertSame(400, $this->call('POST', '/api/auth/login', ['username' => 'alex'], $origin)->getStatusCode());
        self::assertSame(400, $this->call('POST', '/api/auth/login', ['username' => ['a'], 'password' => 'x'], $origin)->getStatusCode());
        self::assertSame(400, $this->call('POST', '/api/auth/login', ['username' => 'alex', 'password' => str_repeat('x', 5000)], $origin)->getStatusCode());
        self::assertSame(405, $this->call('GET', '/api/auth/login')->getStatusCode());
    }

    public function testOldPasswordHashIsUpgradedAfterLogin(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('Argon2id nicht verfügbar.');
        }
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $this->db()->execute('UPDATE users SET password_hash = :h', ['h' => password_hash(self::PASSWORD, PASSWORD_BCRYPT)]);

        self::assertSame(200, $this->login('alex')->getStatusCode());
        $hash = self::dig($this->db()->fetchOne('SELECT password_hash FROM users'), 'password_hash');
        self::assertIsString($hash);
        self::assertStringStartsWith('$argon2id$', $hash);
    }

    // --- Sitzung und Rechte ----------------------------------------------------------------

    public function testMeWithoutSessionIsUnauthorized(): void
    {
        self::assertSame(401, $this->call('GET', '/api/auth/me')->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/auth/me', [], [], [self::COOKIE => 'gefaelscht'])->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/auth/me', [], [], [self::COOKIE => str_repeat('a', 500)])->getStatusCode());
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function roles(): array
    {
        return [
            'Admin' => ['Admin', ['jobs.view', 'jobs.run', 'jobs.edit_http', 'jobs.edit_shell', 'status_pages.edit', 'users.manage', 'network.internal_targets', 'settings.manage', 'categories.manage', 'roles.manage', 'shell.targets']],
            'Operator' => ['Operator', ['jobs.view', 'jobs.run', 'jobs.edit_http', 'status_pages.edit']],
            'Beobachter' => ['Beobachter', ['jobs.view']],
        ];
    }

    /**
     * @param list<string> $expected
     *
     * @dataProvider roles
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function testMeShowsExactlyThePermissionsOfTheRole(string $role, array $expected): void
    {
        $this->users()->create('person', 'Person', self::PASSWORD, $role);
        $cookie = $this->sessionCookie($this->login('person'));

        $data = $this->json($this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $cookie]));
        self::assertSame($role, self::dig($data, 'roles', 0, 'role'));
        $permissions = self::dig($data, 'roles', 0, 'permissions');
        self::assertIsArray($permissions);
        self::assertEqualsCanonicalizing($expected, $permissions);
        self::assertSame('person', self::dig($data, 'user', 'username'));
        self::assertNull(self::dig($data, 'user', 'password_hash'));
        self::assertStringNotContainsString('argon2', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testCategoryRestrictionIsReportedAndEmptyIsNotAll(): void
    {
        $id = $this->users()->create('person', 'Person', self::PASSWORD, 'Operator');
        $this->db()->execute("INSERT INTO categories (id, name) VALUES (1, 'A'), (2, 'B')");
        $grantId = self::dig($this->db()->fetchOne('SELECT id FROM user_roles WHERE user_id = :u', ['u' => $id]), 'id');
        self::assertIsInt($grantId);
        $this->db()->execute('UPDATE user_roles SET all_categories = 0 WHERE id = :g', ['g' => $grantId]);
        $this->db()->execute('INSERT INTO user_role_categories (user_role_id, category_id) VALUES (:g, 1)', ['g' => $grantId]);

        $cookie = $this->sessionCookie($this->login('person'));
        $data = $this->json($this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $cookie]));

        self::assertSame(['A'], self::dig($data, 'roles', 0, 'categories'));
    }

    public function testIdleSessionExpires(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $cookie = $this->sessionCookie($this->login('alex'));

        $this->clock()->advance(SessionManager::IDLE_SECONDS - 60);
        self::assertSame(200, $this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $cookie])->getStatusCode());

        $this->clock()->advance(SessionManager::IDLE_SECONDS + 1);
        self::assertSame(401, $this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $cookie])->getStatusCode());
        self::assertSame(0, $this->sessionCount());
    }

    public function testSessionExpiresAbsoluteEvenWithContinuousActivity(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $cookie = $this->sessionCookie($this->login('alex'));

        for ($elapsed = 0; $elapsed < SessionManager::ABSOLUTE_SECONDS - 3600; $elapsed += 3600) {
            $this->clock()->advance(3600);
            self::assertSame(200, $this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $cookie])->getStatusCode());
        }

        $this->clock()->advance(3601);
        self::assertSame(401, $this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $cookie])->getStatusCode());
    }

    public function testDeactivatedUserLosesAccessImmediately(): void
    {
        $id = $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $cookie = $this->sessionCookie($this->login('alex'));
        self::assertSame(200, $this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $cookie])->getStatusCode());

        $this->db()->execute('UPDATE users SET is_active = 0 WHERE id = :id', ['id' => $id]);
        self::assertSame(401, $this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $cookie])->getStatusCode());
    }

    public function testRoleChangeTakesEffectOnTheNextRequest(): void
    {
        $id = $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $cookie = $this->sessionCookie($this->login('alex'));

        $this->db()->execute('UPDATE user_roles SET role_id = 3 WHERE user_id = :id', ['id' => $id]);
        $data = $this->json($this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $cookie]));

        self::assertSame(['jobs.view'], self::dig($data, 'roles', 0, 'permissions'));
    }

    // --- Abmeldung und CSRF ----------------------------------------------------------------

    public function testLogoutNeedsSessionCsrfTokenAndOrigin(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $login = $this->login('alex');
        $cookie = $this->sessionCookie($login);
        $csrf = $this->csrfOf($login);
        $cookies = [self::COOKIE => $cookie];
        $origin = ['Origin' => 'https://meridian.test'];

        self::assertSame(401, $this->call('POST', '/api/auth/logout', [], $origin + ['X-CSRF-Token' => $csrf])->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/auth/logout', [], $origin, $cookies)->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/auth/logout', [], $origin + ['X-CSRF-Token' => 'falsch'], $cookies)->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/auth/logout', [], ['Origin' => 'https://evil.test', 'X-CSRF-Token' => $csrf], $cookies)->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/auth/logout', [], ['X-CSRF-Token' => $csrf], $cookies)->getStatusCode());
        self::assertSame(200, $this->call('GET', '/api/auth/me', [], [], $cookies)->getStatusCode(), 'Abgelehnte Anfragen beenden die Sitzung nicht.');

        $ok = $this->call('POST', '/api/auth/logout', [], $origin + ['X-CSRF-Token' => $csrf], $cookies);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/auth/me', [], [], $cookies)->getStatusCode(), 'Die Sitzung ist serverseitig gelöscht.');
        $cleared = $ok->headers->getCookies()[0];
        self::assertSame(self::COOKIE, $cleared->getName());
        self::assertLessThan(time(), $cleared->getExpiresTime());
    }

    public function testCsrfTokenOfAnotherSessionIsRejected(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $first = $this->login('alex');
        $second = $this->login('alex');

        $response = $this->call(
            'POST',
            '/api/auth/logout',
            [],
            ['Origin' => 'https://meridian.test', 'X-CSRF-Token' => $this->csrfOf($first)],
            [self::COOKIE => $this->sessionCookie($second)],
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // --- Audit und Leaks -------------------------------------------------------------------

    public function testAuditLogRecordsLoginFailureLockLogoutWithoutSecrets(): void
    {
        $id = $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        for ($i = 0; $i < 5; ++$i) {
            $this->login('alex', 'TEST-WRONG-do-not-use-1');
        }
        $this->clock()->advance(31);
        $login = $this->login('alex');
        $this->call(
            'POST',
            '/api/auth/logout',
            [],
            ['Origin' => 'https://meridian.test', 'X-CSRF-Token' => $this->csrfOf($login)],
            [self::COOKIE => $this->sessionCookie($login)],
        );

        $actions = array_column($this->db()->fetchAll('SELECT action FROM audit_log ORDER BY id'), 'action');
        self::assertSame(
            ['auth.login_failed', 'auth.login_failed', 'auth.login_failed', 'auth.login_failed', 'auth.login_failed', 'auth.login_locked', 'auth.login', 'auth.logout'],
            $actions,
        );
        $users = array_column($this->db()->fetchAll('SELECT user_id FROM audit_log'), 'user_id');
        self::assertSame($id, $users[0]);
    }

    public function testNoSecretEverReachesAuditLogResponsesOrFailureTable(): void
    {
        $secret = 'TEST-SECRET-do-not-use-123';
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');

        $responses = [
            $this->login('alex', $secret),
            $this->login($secret, $secret),
            $this->login('alex', $secret),
        ];
        $dump = json_encode([
            array_map(static fn (Response $r): string => (string) $r->getContent() . $r->headers, $responses),
            $this->db()->fetchAll('SELECT * FROM audit_log'),
            $this->db()->fetchAll('SELECT * FROM login_failures'),
            $this->db()->fetchAll('SELECT * FROM sessions'),
        ], JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($secret, $dump);
        self::assertStringNotContainsString(self::PASSWORD, $dump);
    }

    public function testHealthStaysPublic(): void
    {
        $response = $this->call('GET', '/api/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $this->json($response)['status'] ?? null);
    }
}
