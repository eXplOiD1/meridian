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
        private readonly TwoFactor $twoFactor,
    ) {
    }

    public function login(string $username, #[\SensitiveParameter] string $password, string $ip, #[\SensitiveParameter] ?string $totpCode = null): LoginResult
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

        if ($this->twoFactor->isEnabled($user->id)) {
            // Passwort stimmt, aber ohne Code startet keine Sitzung. Das zählt nicht als Fehlversuch,
            // sonst würde jede normale Anmeldung mit 2FA die Sperre vorbereiten.
            if ($totpCode === null || trim($totpCode) === '') {
                return LoginResult::totpRequired();
            }

            $method = $this->twoFactor->verify($user->id, $totpCode);
            if ($method === null) {
                $this->fail($user, $username, $ip, 'auth.2fa_failed');

                return LoginResult::invalid();
            }
            if ($method === TwoFactorMethod::Recovery) {
                $this->audit->record($user->id, 'auth.recovery_code_used', $user->username);
            }
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

    /**
     * Verbleibende Sperrzeit für Änderungen am zweiten Faktor (gleiche Zähler wie die Anmeldung).
     */
    public function lockedFor(UserAccount $user, string $ip): int
    {
        return $this->throttle->lockedFor($user->username, $ip);
    }

    /**
     * @return array{secret: string, uri: string}|null null, wenn 2FA schon aktiv ist
     */
    public function startTwoFactorSetup(UserAccount $user): ?array
    {
        $setup = $this->twoFactor->beginSetup($user->id, $user->username);
        if ($setup !== null) {
            $this->audit->record($user->id, 'auth.2fa_setup_started', $user->username);
        }

        return $setup;
    }

    /**
     * @return list<string>|null die Wiederherstellungscodes (einmalig), null bei falschem Code
     */
    public function enableTwoFactor(UserAccount $user, string $code, string $ip): ?array
    {
        $codes = $this->twoFactor->confirm($user->id, $code);
        if ($codes === null) {
            $this->fail($user, $user->username, $ip, 'auth.2fa_enable_failed');

            return null;
        }
        $this->audit->record($user->id, 'auth.2fa_enabled', $user->username);

        return $codes;
    }

    /**
     * Abschalten verlangt Passwort und einen gültigen zweiten Faktor, auch mit gültiger Sitzung.
     */
    public function disableTwoFactor(UserAccount $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code, string $ip): bool
    {
        $passwordOk = strlen($password) <= self::MAX_PASSWORD && $this->hasher->verify($password, $user->passwordHash);
        // Der Code wird nur bei richtigem Passwort geprüft: ein falsches Passwort darf keinen gültigen Code verbrauchen.
        $method = $passwordOk ? $this->twoFactor->verify($user->id, $code) : null;
        if (!$passwordOk || $method === null) {
            $this->fail($user, $user->username, $ip, 'auth.2fa_disable_failed');

            return false;
        }

        $this->twoFactor->disable($user->id);
        $this->audit->record($user->id, 'auth.2fa_disabled', $user->username);

        return true;
    }

    public function logout(Session $session): void
    {
        $this->sessions->end($session->token);
        $this->audit->record($session->userId, 'auth.logout');
    }

    private function fail(?UserAccount $user, string $username, string $ip, string $action = 'auth.login_failed'): void
    {
        $newlyLocked = $this->throttle->recordFailure($username, $ip);
        // Ins Audit-Log nur der Name eines bestehenden Kontos. Was bei unbekannten Namen im Feld
        // steht, kann ein versehentlich getipptes Passwort sein und wird nie gespeichert.
        $target = $user?->username;
        $this->audit->record($user?->id, $action, $target);
        if ($newlyLocked) {
            $this->audit->record($user?->id, 'auth.login_locked', $target);
        }
    }

    private function dummyHash(): string
    {
        return $this->dummyHash ??= $this->hasher->hash(bin2hex(random_bytes(16)));
    }
}
