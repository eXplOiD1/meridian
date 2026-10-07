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
        // Den Versuch zuerst reservieren (atomar), erst danach das langsame Passwort prüfen: Parallele Anfragen
        // bringen so keine zusätzlichen Versuche, und die Antwort hängt nie davon ab, ob das Passwort stimmt.
        $reservation = $this->throttle->reserve($username, $ip);
        if (!$reservation->allowed) {
            return LoginResult::locked($reservation->retryAfter);
        }

        $user = strlen($username) <= self::MAX_USERNAME ? $this->users->findByUsername($username) : null;
        $hash = $user !== null ? $user->passwordHash : PasswordHasher::DUMMY_HASH;

        $acceptable = strlen($password) <= self::MAX_PASSWORD;
        $verified = $acceptable && $this->hasher->verify($password, $hash);

        if ($user === null || !$user->isActive || !$verified) {
            $this->fail($user, $reservation);

            return LoginResult::invalid();
        }

        if ($this->twoFactor->isEnabled($user->id)) {
            // Passwort stimmt, aber ohne Code startet keine Sitzung. Das zählt nicht als Fehlversuch,
            // sonst würde jede normale Anmeldung mit 2FA die Sperre vorbereiten: der Platz wird freigegeben.
            if ($totpCode === null || trim($totpCode) === '') {
                $this->throttle->release($username, $ip);

                return LoginResult::totpRequired();
            }

            $method = $this->twoFactor->verify($user->id, $totpCode);
            if ($method === null) {
                $this->fail($user, $reservation, 'auth.2fa_failed');

                return LoginResult::invalid();
            }
            if ($method === TwoFactorMethod::Recovery) {
                $this->audit->record($user->id, 'auth.recovery_code_used', $user->username);
            }
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }

        $this->throttle->release($username, $ip);
        $this->users->markLogin($user->id, $this->clock->now());
        $session = $this->sessions->start($user->id);
        $this->audit->record($user->id, 'auth.login', $user->username);

        return LoginResult::success($session);
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
     * Aktivieren verlangt wie das Abschalten das Passwort: mit einer gestohlenen Sitzung allein lässt sich
     * 2FA nicht mit dem eigenen Gerät einschalten und der Inhaber aussperren.
     *
     * @return list<string>|null die Wiederherstellungscodes (einmalig), null bei falschem Passwort oder Code
     */
    public function enableTwoFactor(UserAccount $user, #[\SensitiveParameter] string $password, string $code, string $ip): ?array
    {
        $reservation = $this->reserveOrFail($user, $ip);
        $passwordOk = strlen($password) <= self::MAX_PASSWORD && $this->hasher->verify($password, $user->passwordHash);
        $codes = $passwordOk ? $this->twoFactor->confirm($user->id, $code) : null;
        if ($codes === null) {
            $this->fail($user, $reservation, 'auth.2fa_enable_failed');

            return null;
        }
        $this->throttle->release($user->username, $ip);
        $this->audit->record($user->id, 'auth.2fa_enabled', $user->username);

        return $codes;
    }

    /**
     * Abschalten verlangt Passwort und einen gültigen zweiten Faktor, auch mit gültiger Sitzung.
     */
    public function disableTwoFactor(UserAccount $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code, string $ip): bool
    {
        $reservation = $this->reserveOrFail($user, $ip);
        $passwordOk = strlen($password) <= self::MAX_PASSWORD && $this->hasher->verify($password, $user->passwordHash);
        // Der Code wird nur bei richtigem Passwort geprüft: ein falsches Passwort darf keinen gültigen Code verbrauchen.
        $method = $passwordOk ? $this->twoFactor->verify($user->id, $code) : null;
        if (!$passwordOk || $method === null) {
            $this->fail($user, $reservation, 'auth.2fa_disable_failed');

            return false;
        }

        $this->throttle->release($user->username, $ip);
        $this->twoFactor->disable($user->id);
        $this->audit->record($user->id, 'auth.2fa_disabled', $user->username);

        return true;
    }

    public function logout(Session $session): void
    {
        $this->sessions->end($session->token);
        $this->audit->record($session->userId, 'auth.logout');
    }

    /**
     * Der Versuch bleibt gezählt (reserviert). Das Audit-Log bekommt nur den Namen eines bestehenden Kontos:
     * was bei unbekannten Namen im Feld steht, kann ein versehentlich getipptes Passwort sein.
     */
    private function fail(?UserAccount $user, Reservation $reservation, string $action = 'auth.login_failed'): void
    {
        $target = $user?->username;
        $this->audit->record($user?->id, $action, $target);
        if ($reservation->reachedLock) {
            $this->audit->record($user?->id, 'auth.login_locked', $target);
        }
    }

    /**
     * @throws TooManyAttempts wenn das Konto oder die IP gesperrt ist; dann wird nichts geprüft
     */
    private function reserveOrFail(UserAccount $user, string $ip): Reservation
    {
        $reservation = $this->throttle->reserve($user->username, $ip);
        if (!$reservation->allowed) {
            throw new TooManyAttempts($reservation->retryAfter);
        }

        return $reservation;
    }
}
