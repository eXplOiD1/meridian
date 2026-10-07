<?php

declare(strict_types=1);

namespace Meridian\Auth;

final readonly class LoginResult
{
    private function __construct(
        public LoginStatus $status,
        public ?Session $session,
        public int $retryAfter,
        public ?int $userId,
    ) {
    }

    public static function success(Session $session): self
    {
        return new self(LoginStatus::Success, $session, 0, $session->userId);
    }

    public static function invalid(): self
    {
        return new self(LoginStatus::Invalid, null, 0, null);
    }

    public static function totpRequired(): self
    {
        return new self(LoginStatus::TotpRequired, null, 0, null);
    }

    public static function locked(int $retryAfter): self
    {
        return new self(LoginStatus::Locked, null, $retryAfter, null);
    }
}
