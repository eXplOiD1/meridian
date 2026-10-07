<?php

declare(strict_types=1);

namespace Meridian\Auth;

use Meridian\Security\PasswordHasher;
use Meridian\User\UserAccount;
use Meridian\User\UserRepository;

/**
 * Anmeldung mit Passwort. Fehlermeldung und Laufzeit sind für „Benutzer unbekannt“,
 * „Passwort falsch“ und „Benutzer deaktiviert“ gleich: ein unbekannter Name wird
 * trotzdem gegen einen Dummy-Hash geprüft.
 */
final class AuthService
{
    public const MESSAGE_INVALID = 'Benutzername oder Passwort falsch.';
    private const MAX_USERNAME = 64;
    /** Obergrenze gegen Rechenlast-Missbrauch (Argon2id). */
    private const MAX_PASSWORD = 1024;

    private ?string $dummyHash = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly LoginThrottle $throttle,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    public function login(string $username, #[\SensitiveParameter] string $password, string $ip): LoginResult
    {
        $locked = $this->throttle->lockedFor($username, $ip);
        if ($locked > 0) {
            return LoginResult::locked($locked);
        }

        $user = strlen($username) <= self::MAX_USERNAME ? $this->users->findByUsername($username) : null;
        $hash = $user !== null ? $user->passwordHash : $this->dummyHash();

        $acceptable = strlen($password) <= self::MAX_PASSWORD;
        $verified = $acceptable && $this->hasher->verify($password, $hash);

        if ($user === null || !$user->isActive || !$verified) {
            $this->fail($user, $username, $ip);

            return LoginResult::invalid();
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }

        $this->throttle->clearUser($username);
        $this->users->markLogin($user->id, $this->clock->now());
        $session = $this->sessions->start($user->id);
        $this->audit->record($user->id, 'auth.login', $user->username);

        return LoginResult::success($session);
    }

    public function logout(Session $session): void
    {
        $this->sessions->end($session->token);
        $this->audit->record($session->userId, 'auth.logout');
    }

    private function fail(?UserAccount $user, string $username, string $ip): void
    {
        $newlyLocked = $this->throttle->recordFailure($username, $ip);
        // Ins Audit-Log nur der Name eines bestehenden Kontos. Was bei unbekannten Namen im Feld
        // steht, kann ein versehentlich getipptes Passwort sein und wird nie gespeichert.
        $target = $user?->username;
        $this->audit->record($user?->id, 'auth.login_failed', $target);
        if ($newlyLocked) {
            $this->audit->record($user?->id, 'auth.login_locked', $target);
        }
    }

    private function dummyHash(): string
    {
        return $this->dummyHash ??= $this->hasher->hash(bin2hex(random_bytes(16)));
    }
}
