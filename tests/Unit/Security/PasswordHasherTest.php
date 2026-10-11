<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Security;

use Meridian\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testDummyHashHasTheSameCostAsRealHashes(): void
    {
        $hasher = new PasswordHasher();

        self::assertStringStartsWith('$argon2id$', PasswordHasher::DUMMY_HASH);
        self::assertFalse(
            $hasher->needsRehash(PasswordHasher::DUMMY_HASH),
            'Die Kosten haben sich geändert: DUMMY_HASH neu erzeugen (password_hash mit PASSWORD_ARGON2ID), sonst ist die Anmeldung für unbekannte Benutzer schneller.',
        );
    }

    public function testDummyHashNeverVerifiesARealisticPassword(): void
    {
        $hasher = new PasswordHasher();

        foreach (['', 'password', 'TEST-PASSWORD-do-not-use-1', PasswordHasher::DUMMY_HASH] as $guess) {
            self::assertFalse($hasher->verify($guess, PasswordHasher::DUMMY_HASH));
        }
    }

    public function testMinimumLengthIsEightCharacters(): void
    {
        $hasher = new PasswordHasher();

        self::assertStringStartsWith('$argon2id$', $hasher->hash('12345678'));
        $this->expectException(\InvalidArgumentException::class);
        $hasher->hash('1234567');
    }
}
