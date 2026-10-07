<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Security;

use Meridian\Security\ApiToken;
use Meridian\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordAndTokenTest extends TestCase
{
    public function testPasswordHashing(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('ein-langes-passwort');

        self::assertStringNotContainsString('ein-langes-passwort', $hash);
        self::assertTrue($hasher->verify('ein-langes-passwort', $hash));
        self::assertFalse($hasher->verify('falsches-passwort', $hash));
    }

    public function testShortPasswordIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PasswordHasher())->hash('kurz');
    }

    public function testTokenIsStoredOnlyAsHash(): void
    {
        $token = ApiToken::issue();

        self::assertStringStartsWith('mrd_', $token['plain']);
        self::assertStringNotContainsString($token['plain'], $token['hash']);
        self::assertTrue(ApiToken::matches($token['plain'], $token['hash']));
        self::assertFalse(ApiToken::matches('mrd_falsch', $token['hash']));
    }
}
