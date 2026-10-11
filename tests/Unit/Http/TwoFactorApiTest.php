<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Http;

use Meridian\Auth\Totp;
use Meridian\Tests\Support\ApiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class TwoFactorApiTest extends TestCase
{
    use ApiHarness;

    private const ORIGIN = 'https://meridian.test';

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body, string $cookie, string $csrf): Response
    {
        return $this->call('POST', $path, $body, ['Origin' => self::ORIGIN, 'X-CSRF-Token' => $csrf], [self::COOKIE => $cookie]);
    }

    private function codeNow(string $secret, int $offset = 0): string
    {
        return Totp::code($secret, Totp::stepAt($this->clock()->now()) + $offset);
    }

    /**
     * Legt einen Benutzer an, richtet 2FA ein und bestätigt es.
     *
     * @return array{secret: string, recovery: list<string>, cookie: string, csrf: string}
     */
    private function enrolled(string $username = 'alex', string $role = 'Admin'): array
    {
        $this->users()->create($username, ucfirst($username), self::PASSWORD, $role);
        $login = $this->login($username);
        $cookie = $this->sessionCookie($login);
        $csrf = $this->csrfOf($login);

        $setup = $this->json($this->post('/api/auth/2fa/setup', [], $cookie, $csrf));
        $secret = self::dig($setup, 'secret');
        self::assertIsString($secret);

        $enable = $this->json($this->post('/api/auth/2fa/enable', ['password' => self::PASSWORD, 'code' => $this->codeNow($secret)], $cookie, $csrf));
        $recovery = self::dig($enable, 'recovery_codes');
        self::assertIsArray($recovery);
        /** @var list<string> $recovery */

        return ['secret' => $secret, 'recovery' => $recovery, 'cookie' => $cookie, 'csrf' => $csrf];
    }

    /**
     * @return Response Anmeldung mit Passwort und optionalem Code
     */
    private function loginWithCode(string $username, ?string $code, string $ip = '10.0.0.5'): Response
    {
        $body = ['username' => $username, 'password' => self::PASSWORD];
        if ($code !== null) {
            $body['totp_code'] = $code;
        }

        return $this->call('POST', '/api/auth/login', $body, ['Origin' => self::ORIGIN], [], $ip);
    }

    // --- Einrichtung ---------------------------------------------------------------------------

    public function testSetupNeedsSessionCsrfAndOrigin(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $login = $this->login('alex');
        $cookie = $this->sessionCookie($login);
        $csrf = $this->csrfOf($login);

        self::assertSame(401, $this->call('POST', '/api/auth/2fa/setup', [], ['Origin' => self::ORIGIN, 'X-CSRF-Token' => $csrf])->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/auth/2fa/setup', [], ['Origin' => self::ORIGIN], [self::COOKIE => $cookie])->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/auth/2fa/setup', [], ['Origin' => 'https://evil.test', 'X-CSRF-Token' => $csrf], [self::COOKIE => $cookie])->getStatusCode());
        self::assertSame(405, $this->call('GET', '/api/auth/2fa/setup', [], [], [self::COOKIE => $cookie])->getStatusCode());
        self::assertNull($this->db()->fetchOne('SELECT 1 AS x FROM users WHERE totp_secret_enc IS NOT NULL'));
    }

    public function testSecretIsShownOnceAndStoredOnlyEncrypted(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $login = $this->login('alex');
        $cookie = $this->sessionCookie($login);
        $csrf = $this->csrfOf($login);

        $setup = $this->post('/api/auth/2fa/setup', [], $cookie, $csrf);
        $data = $this->json($setup);
        $secret = self::dig($data, 'secret');

        self::assertSame(200, $setup->getStatusCode());
        self::assertIsString($secret);
        $uri = self::dig($data, 'otpauth_uri');
        self::assertIsString($uri);
        self::assertStringContainsString($secret, $uri);
        self::assertStringContainsString('no-store', (string) $setup->headers->get('Cache-Control'));

        $stored = self::dig($this->db()->fetchOne('SELECT totp_secret_enc FROM users'), 'totp_secret_enc');
        self::assertIsString($stored);
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString($secret, $stored);
        self::assertSame($secret, $this->box()->decrypt($stored));

        // Noch nicht bestätigt: die Anmeldung verlangt keinen Code, /me zeigt es nicht als aktiv.
        $me = $this->json($this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $cookie]));
        self::assertFalse(self::dig($me, 'totp_enabled'));
        self::assertStringNotContainsString($secret, json_encode($me, JSON_THROW_ON_ERROR));
        self::assertSame(200, $this->login('alex')->getStatusCode());
    }

    public function testWrongCodeDoesNotEnableAndFailuresLockTheAccount(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $login = $this->login('alex');
        $cookie = $this->sessionCookie($login);
        $csrf = $this->csrfOf($login);
        $this->post('/api/auth/2fa/setup', [], $cookie, $csrf);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(400, $this->post('/api/auth/2fa/enable', ['password' => self::PASSWORD, 'code' => '000000'], $cookie, $csrf)->getStatusCode());
        }

        $locked = $this->post('/api/auth/2fa/enable', ['password' => self::PASSWORD, 'code' => '000000'], $cookie, $csrf);
        self::assertSame(429, $locked->getStatusCode());
        self::assertNotNull($locked->headers->get('Retry-After'));
        self::assertSame(0, self::dig($this->db()->fetchOne('SELECT totp_enabled FROM users'), 'totp_enabled'));
    }

    public function testEnableNeedsThePasswordSoAStolenSessionCannotLockTheOwnerOut(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $login = $this->login('alex');
        $cookie = $this->sessionCookie($login);
        $csrf = $this->csrfOf($login);
        $secret = self::dig($this->json($this->post('/api/auth/2fa/setup', [], $cookie, $csrf)), 'secret');
        self::assertIsString($secret);
        $code = $this->codeNow($secret);

        // Der Angreifer kennt Sitzung und Code seines eigenen Geräts, aber nicht das Passwort.
        self::assertSame(400, $this->post('/api/auth/2fa/enable', ['code' => $code], $cookie, $csrf)->getStatusCode());
        self::assertSame(400, $this->post('/api/auth/2fa/enable', ['password' => 'TEST-WRONG-do-not-use-1', 'code' => $code], $cookie, $csrf)->getStatusCode());
        self::assertFalse($this->twoFactorActive());

        self::assertSame(200, $this->post('/api/auth/2fa/enable', ['password' => self::PASSWORD, 'code' => $code], $cookie, $csrf)->getStatusCode());
        self::assertTrue($this->twoFactorActive());
    }

    public function testWrongPasswordDuringEnableDoesNotConsumeTheCodeButCountsForTheLock(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $login = $this->login('alex');
        $cookie = $this->sessionCookie($login);
        $csrf = $this->csrfOf($login);
        $secret = self::dig($this->json($this->post('/api/auth/2fa/setup', [], $cookie, $csrf)), 'secret');
        self::assertIsString($secret);

        for ($i = 0; $i < 5; ++$i) {
            $this->post('/api/auth/2fa/enable', ['password' => 'TEST-WRONG-do-not-use-1', 'code' => $this->codeNow($secret)], $cookie, $csrf);
        }

        self::assertSame(429, $this->post('/api/auth/2fa/enable', ['password' => self::PASSWORD, 'code' => $this->codeNow($secret)], $cookie, $csrf)->getStatusCode());
    }

    public function testEnableNeedsAPendingSetup(): void
    {
        $this->users()->create('alex', 'Alex', self::PASSWORD, 'Admin');
        $login = $this->login('alex');

        $response = $this->post('/api/auth/2fa/enable', ['password' => self::PASSWORD, 'code' => '123456'], $this->sessionCookie($login), $this->csrfOf($login));
        self::assertSame(400, $response->getStatusCode());
    }

    public function testEnableReturnsRecoveryCodesOnceAndStoresOnlyHashes(): void
    {
        $setup = $this->enrolled();

        self::assertCount(8, $setup['recovery']);
        foreach ($setup['recovery'] as $code) {
            self::assertMatchesRegularExpression('/^[A-Z2-7]{4}-[A-Z2-7]{4}-[A-Z2-7]{4}$/', $code);
        }
        self::assertCount(8, array_unique($setup['recovery']));

        $rows = $this->db()->fetchAll('SELECT code_hash, used_at FROM recovery_codes');
        self::assertCount(8, $rows);
        foreach ($rows as $row) {
            $hash = self::dig($row, 'code_hash');
            self::assertIsString($hash);
            self::assertStringStartsWith('$argon2', $hash);
            self::assertNull(self::dig($row, 'used_at'));
        }
        $dump = json_encode($rows, JSON_THROW_ON_ERROR);
        foreach ($setup['recovery'] as $code) {
            self::assertStringNotContainsString(str_replace('-', '', $code), $dump);
        }

        $me = $this->json($this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $setup['cookie']]));
        self::assertTrue(self::dig($me, 'totp_enabled'));
        self::assertStringNotContainsString('recovery', json_encode($me, JSON_THROW_ON_ERROR));
    }

    public function testSetupIsRefusedWhileTwoFactorIsActive(): void
    {
        $setup = $this->enrolled();
        $before = self::dig($this->db()->fetchOne('SELECT totp_secret_enc FROM users'), 'totp_secret_enc');

        $response = $this->post('/api/auth/2fa/setup', [], $setup['cookie'], $setup['csrf']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame($before, self::dig($this->db()->fetchOne('SELECT totp_secret_enc FROM users'), 'totp_secret_enc'));
        self::assertStringNotContainsString($setup['secret'], (string) $response->getContent());
    }

    // --- Anmeldung mit zweitem Faktor ----------------------------------------------------------

    public function testLoginNeedsTheCodeOnceTwoFactorIsActive(): void
    {
        $setup = $this->enrolled();
        $this->clock()->advance(Totp::PERIOD);

        $noCode = $this->loginWithCode('alex', null);
        self::assertSame(401, $noCode->getStatusCode());
        self::assertTrue(self::dig($this->json($noCode), 'totp_required'));
        self::assertCount(0, $noCode->headers->getCookies());

        $wrong = $this->loginWithCode('alex', '000000');
        self::assertSame(401, $wrong->getStatusCode());
        self::assertSame('Benutzername oder Passwort falsch.', self::dig($this->json($wrong), 'error'));
        self::assertNull(self::dig($this->json($wrong), 'totp_required'));
        self::assertCount(0, $wrong->headers->getCookies());

        $ok = $this->loginWithCode('alex', $this->codeNow($setup['secret']));
        self::assertSame(200, $ok->getStatusCode());
        self::assertCount(1, $ok->headers->getCookies());
    }

    public function testMissingCodeIsNotCountedButWrongCodesLockTheAccount(): void
    {
        $this->enrolled();
        $this->clock()->advance(Totp::PERIOD);

        for ($i = 0; $i < 10; ++$i) {
            self::assertSame(401, $this->loginWithCode('alex', null)->getStatusCode());
        }
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(401, $this->loginWithCode('alex', '000000')->getStatusCode());
        }

        self::assertSame(429, $this->loginWithCode('alex', '000000')->getStatusCode());
    }

    public function testACodeCannotBeUsedTwice(): void
    {
        $setup = $this->enrolled();

        // Der Code der Einrichtung ist verbraucht, auch wenn er noch im Zeitfenster liegt.
        self::assertSame(401, $this->loginWithCode('alex', $this->codeNow($setup['secret']))->getStatusCode());

        $this->clock()->advance(Totp::PERIOD);
        $code = $this->codeNow($setup['secret']);
        self::assertSame(200, $this->loginWithCode('alex', $code)->getStatusCode());
        self::assertSame(401, $this->loginWithCode('alex', $code)->getStatusCode(), 'Replay im selben Schritt');
    }

    public function testRecoveryCodeWorksExactlyOnce(): void
    {
        $setup = $this->enrolled();
        $code = $setup['recovery'][0];

        self::assertSame(200, $this->loginWithCode('alex', strtolower(str_replace('-', ' ', $code)))->getStatusCode());
        self::assertSame(401, $this->loginWithCode('alex', $code)->getStatusCode());
        self::assertSame(200, $this->loginWithCode('alex', $setup['recovery'][1])->getStatusCode(), 'andere Codes bleiben gültig');

        $actions = array_column($this->db()->fetchAll('SELECT action FROM audit_log'), 'action');
        self::assertSame(2, count(array_keys($actions, 'auth.recovery_code_used', true)));
    }

    public function testWrongPasswordNeverRevealsWhetherTwoFactorIsActive(): void
    {
        $this->enrolled();
        $this->users()->create('plain', 'Plain', self::PASSWORD, 'Operator');

        $withTotp = $this->login('alex', 'TEST-WRONG-do-not-use-1');
        $without = $this->login('plain', 'TEST-WRONG-do-not-use-1');

        self::assertSame((string) $withTotp->getContent(), (string) $without->getContent());
    }

    // --- Abschalten ----------------------------------------------------------------------------

    public function testDisableNeedsPasswordAndSecondFactor(): void
    {
        $setup = $this->enrolled();
        $this->clock()->advance(Totp::PERIOD);
        $disable = fn (string $password, string $code): Response => $this->post('/api/auth/2fa/disable', ['password' => $password, 'code' => $code], $setup['cookie'], $setup['csrf']);

        self::assertSame(400, $disable('TEST-WRONG-do-not-use-1', $this->codeNow($setup['secret']))->getStatusCode());
        self::assertSame(400, $disable(self::PASSWORD, '000000')->getStatusCode());
        self::assertSame(400, $this->post('/api/auth/2fa/disable', [], $setup['cookie'], $setup['csrf'])->getStatusCode());
        self::assertTrue($this->twoFactorActive());

        self::assertSame(200, $disable(self::PASSWORD, $this->codeNow($setup['secret']))->getStatusCode());
        self::assertFalse($this->twoFactorActive());
        self::assertNull($this->db()->fetchOne('SELECT 1 AS x FROM recovery_codes'));
        self::assertNull(self::dig($this->db()->fetchOne('SELECT totp_secret_enc FROM users'), 'totp_secret_enc'));
        self::assertSame(200, $this->login('alex')->getStatusCode(), 'ohne 2FA genügt das Passwort wieder');
    }

    public function testDisableNeedsSessionAndCsrf(): void
    {
        $setup = $this->enrolled();
        $body = ['password' => self::PASSWORD, 'code' => $setup['recovery'][0]];

        self::assertSame(401, $this->call('POST', '/api/auth/2fa/disable', $body, ['Origin' => self::ORIGIN, 'X-CSRF-Token' => $setup['csrf']])->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/auth/2fa/disable', $body, ['Origin' => self::ORIGIN], [self::COOKIE => $setup['cookie']])->getStatusCode());
        self::assertTrue($this->twoFactorActive());
    }

    private function twoFactorActive(): bool
    {
        return self::dig($this->db()->fetchOne('SELECT totp_enabled FROM users'), 'totp_enabled') === 1;
    }

    // --- Rollen und Leaks ----------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function roles(): array
    {
        return ['Admin' => ['Admin'], 'Operator' => ['Operator'], 'Beobachter' => ['Beobachter']];
    }

    #[DataProvider('roles')]
    public function testEveryRoleCanProtectItsOwnAccountButNobodyElses(string $role): void
    {
        $setup = $this->enrolled('person', $role);
        self::assertTrue($this->twoFactorActive());
        self::assertNotSame('', $setup['cookie']);

        $this->users()->create('fremd', 'Fremd', self::PASSWORD, 'Admin');
        $others = $this->db()->fetchAll("SELECT totp_enabled FROM users WHERE username = 'fremd'");
        self::assertSame(0, self::dig($others, 0, 'totp_enabled'));
    }

    public function testNoSecretEverReachesAuditLogFailuresSessionsOrOrdinaryResponses(): void
    {
        $setup = $this->enrolled();
        $this->clock()->advance(Totp::PERIOD);

        $responses = [
            $this->loginWithCode('alex', null),
            $this->loginWithCode('alex', '000000'),
            $this->loginWithCode('alex', $setup['recovery'][2]),
            $this->call('GET', '/api/auth/me', [], [], [self::COOKIE => $setup['cookie']]),
            $this->post('/api/auth/2fa/setup', [], $setup['cookie'], $setup['csrf']),
            $this->post('/api/auth/2fa/disable', ['password' => 'TEST-WRONG-do-not-use-1', 'code' => $setup['secret']], $setup['cookie'], $setup['csrf']),
        ];
        $dump = json_encode([
            array_map(static fn (Response $r): string => (string) $r->getContent() . $r->headers, $responses),
            $this->db()->fetchAll('SELECT * FROM audit_log'),
            $this->db()->fetchAll('SELECT * FROM login_failures'),
            $this->db()->fetchAll('SELECT * FROM sessions'),
        ], JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($setup['secret'], $dump);
        self::assertStringNotContainsString(self::PASSWORD, $dump);
        foreach ($setup['recovery'] as $code) {
            self::assertStringNotContainsString($code, $dump);
            self::assertStringNotContainsString(str_replace('-', '', $code), $dump);
        }
    }

    public function testAuditLogRecordsTheLifecycle(): void
    {
        $setup = $this->enrolled();
        $this->clock()->advance(Totp::PERIOD);
        $this->loginWithCode('alex', '000000');
        $this->post('/api/auth/2fa/disable', ['password' => self::PASSWORD, 'code' => $this->codeNow($setup['secret'])], $setup['cookie'], $setup['csrf']);

        $actions = array_column($this->db()->fetchAll('SELECT action FROM audit_log ORDER BY id'), 'action');

        self::assertSame(
            ['auth.login', 'auth.2fa_setup_started', 'auth.2fa_enabled', 'auth.2fa_failed', 'auth.2fa_disabled'],
            $actions,
        );
    }
}
