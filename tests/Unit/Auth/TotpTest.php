<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Auth;

use Meridian\Auth\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** ASCII „12345678901234567890“ als Base32, das Testsecret aus RFC 6238. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @return array<string, array{int, string}>
     */
    public static function rfcVectors(): array
    {
        // RFC 6238, Anhang B (SHA1, 8 Stellen), hier auf die letzten 6 gekürzt.
        return [
            'T=59' => [59, '287082'],
            'T=1111111109' => [1111111109, '081804'],
            'T=1111111111' => [1111111111, '050471'],
            'T=1234567890' => [1234567890, '005924'],
            'T=2000000000' => [2000000000, '279037'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function testMatchesRfc6238TestVectors(int $time, string $expected): void
    {
        self::assertSame($expected, Totp::code(self::RFC_SECRET, intdiv($time, 30)));
    }

    public function testBase32RoundTripAndSecretShape(): void
    {
        $secret = Totp::generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertSame(20, strlen(Totp::base32Decode($secret)));
        self::assertSame($secret, Totp::base32Encode(Totp::base32Decode($secret)));
        self::assertNotSame($secret, Totp::generateSecret());
    }

    public function testInvalidBase32IsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totp::base32Decode('NICHT-GÜLTIG!');
    }

    public function testWindowAcceptsOneStepEitherSideButNotTwo(): void
    {
        $now = new \DateTimeImmutable('@1700000000');
        $step = Totp::stepAt($now);

        self::assertSame($step, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step), $now, null));
        self::assertSame($step - 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 1), $now, null));
        self::assertSame($step + 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 1), $now, null));
        self::assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 2), $now, null));
        self::assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 2), $now, null));
    }

    public function testLastStepBlocksReplayAndEarlierSteps(): void
    {
        $now = new \DateTimeImmutable('@1700000000');
        $step = Totp::stepAt($now);
        $code = Totp::code(self::RFC_SECRET, $step);

        self::assertNull(Totp::verify(self::RFC_SECRET, $code, $now, $step), 'derselbe Schritt zweimal');
        self::assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 1), $now, $step), 'früherer Schritt');
        self::assertSame($step + 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 1), $now, $step));
    }

    public function testMalformedCodesAreRejected(): void
    {
        $now = new \DateTimeImmutable('@1700000000');

        foreach (['', '12345', '1234567', 'abcdef', '12 345', '１２３４５６'] as $code) {
            self::assertNull(Totp::verify(self::RFC_SECRET, $code, $now, null), 'Eingabe: ' . $code);
        }
    }

    public function testProvisioningUri(): void
    {
        $uri = Totp::uri(self::RFC_SECRET, 'Meridian', 'alex@example.test');

        self::assertStringStartsWith('otpauth://totp/Meridian%3Aalex%40example.test?secret=' . self::RFC_SECRET, $uri);
        self::assertStringContainsString('issuer=Meridian', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }
}
