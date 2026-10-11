<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Auth;

use Meridian\Auth\CsrfGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CsrfGuardTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers): Request
    {
        $server = ['HTTP_HOST' => 'meridian.test'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('https://meridian.test/api/x', 'POST', [], [], [], $server);
    }

    public function testTokenIsBoundToSession(): void
    {
        $guard = new CsrfGuard();

        self::assertSame($guard->tokenFor('session-a'), $guard->tokenFor('session-a'));
        self::assertNotSame($guard->tokenFor('session-a'), $guard->tokenFor('session-b'));
    }

    public function testValidTokenAndOrigin(): void
    {
        $guard = new CsrfGuard();
        $request = $this->request(['X-CSRF-Token' => $guard->tokenFor('s1'), 'Origin' => 'https://meridian.test']);

        self::assertTrue($guard->check($request, 's1'));
    }

    public function testWrongOrMissingTokenIsRejected(): void
    {
        $guard = new CsrfGuard();

        self::assertFalse($guard->check($this->request(['Origin' => 'https://meridian.test']), 's1'));
        self::assertFalse($guard->check($this->request(['X-CSRF-Token' => $guard->tokenFor('other'), 'Origin' => 'https://meridian.test']), 's1'));
    }

    public function testForeignOriginIsRejectedEvenWithValidToken(): void
    {
        $guard = new CsrfGuard();
        $request = $this->request(['X-CSRF-Token' => $guard->tokenFor('s1'), 'Origin' => 'https://evil.test']);

        self::assertFalse($guard->check($request, 's1'));
    }

    public function testMissingOriginAndRefererIsRejected(): void
    {
        $guard = new CsrfGuard();

        self::assertFalse($guard->check($this->request(['X-CSRF-Token' => $guard->tokenFor('s1')]), 's1'));
        self::assertFalse($guard->originMatches($this->request(['Origin' => 'null'])));
    }

    public function testRefererIsUsedWhenOriginIsMissingAndPortIsIgnored(): void
    {
        $guard = new CsrfGuard();

        self::assertTrue($guard->originMatches($this->request(['Referer' => 'https://meridian.test:8090/app'])));
        self::assertFalse($guard->originMatches($this->request(['Referer' => 'https://meridian.test.evil.test/'])));
    }
}
