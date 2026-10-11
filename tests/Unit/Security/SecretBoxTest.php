<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Security;

use Meridian\Security\SecretBox;
use Meridian\Security\SecretException;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $box = new SecretBox(sodium_crypto_secretbox_keygen());
        $encrypted = $box->encrypt('https://example.test/cron.php?key=abc123def456');

        self::assertStringStartsWith('v1:', $encrypted);
        self::assertStringNotContainsString('abc123def456', $encrypted);
        self::assertSame('https://example.test/cron.php?key=abc123def456', $box->decrypt($encrypted));
    }

    public function testSamePlaintextGivesDifferentCiphertext(): void
    {
        $box = new SecretBox(sodium_crypto_secretbox_keygen());

        self::assertNotSame($box->encrypt('geheim'), $box->encrypt('geheim'));
    }

    public function testWrongKeyIsRejected(): void
    {
        $encrypted = (new SecretBox(sodium_crypto_secretbox_keygen()))->encrypt('geheim');

        $this->expectException(SecretException::class);
        (new SecretBox(sodium_crypto_secretbox_keygen()))->decrypt($encrypted);
    }

    public function testTamperedValueIsRejected(): void
    {
        $box = new SecretBox(sodium_crypto_secretbox_keygen());
        $encrypted = $box->encrypt('geheim');
        $tampered = substr($encrypted, 0, -2) . (str_ends_with($encrypted, 'A=') ? 'B=' : 'A=');

        $this->expectException(SecretException::class);
        $box->decrypt($tampered);
    }

    public function testKeyIsNotVisibleInDebugOutput(): void
    {
        $key = sodium_crypto_secretbox_keygen();
        $box = new SecretBox($key);

        self::assertStringNotContainsString($key, print_r($box, true));
    }

    public function testCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new SecretBox(sodium_crypto_secretbox_keygen()));
    }
}
