<?php

declare(strict_types=1);

namespace Meridian\Security;

final class PasswordHasher
{
    private const MIN_LENGTH = 8;

    public function hash(#[\SensitiveParameter] string $password): string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException('Das Passwort muss mindestens 8 Zeichen lang sein.');
        }

        return password_hash($password, self::algorithm());
    }

    public function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
