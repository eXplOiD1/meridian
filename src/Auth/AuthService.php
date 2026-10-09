<?php

declare(strict_types=1);

namespace Meridian\Auth;

use Meridian\Database\Connection;
use Meridian\Http\ValidationFailed;
use Meridian\Security\PasswordHasher;
use Meridian\User\UserAccount;
use Meridian\User\UserRepository;

/**
 * Anmeldung mit Passwort. Fehlermeldung und Laufzeit sind für „Benutzer unbekannt“,
 * „Passwort falsch“, „Benutzer deaktiviert/gelöscht“ und „Einmalpasswort abgelaufen“ gleich: ein unbekannter Name
 * wird trotzdem gegen einen Dummy-Hash geprüft.
 *
 * Jede Prüfung eines Passworts — Anmeldung, Passwort-Bestätigung für gefährliche Verwaltungsaktionen, eigener
 * Passwortwechsel, 2FA an/aus — läuft über dieselbe Sperre (`LoginThrottle::reserve()` vor der Prüfung).
 */
final class AuthService
{
    public const MESSAGE_INVALID = 'Benutzername oder Passwort falsch.';
    public const MESSAGE_NEW_TOO_LONG = 'Das neue Passwort darf höchstens 1024 Byte lang sein.';
    public const MESSAGE_NEW_TOO_SHORT = 'Das neue Passwort muss mindestens 8 Zeichen lang sein.';
    public const MESSAGE_NEW_SAME = 'Das neue Passwort muss sich vom bisherigen unterscheiden.';
    private const MAX_USERNAME = 64;
    /** Obergrenze gegen Rechenlast-Missbrauch (Argon2id). */
    public const MAX_PASSWORD = 1024;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly LoginThrottle $throttle,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly TwoFactor $twoFactor,
        private readonly Connection $db,
    ) {
    }

    /**
     * @param string|null $userAgent Browser-Kennung für die Sitzungsübersicht (gekürzt und gesäubert gespeichert)
     */
    public function login(string $username, #[\SensitiveParameter] string $password, string $ip, #[\SensitiveParameter] ?string $totpCode = null, ?string $userAgent = null): LoginResult
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

        // Abgelaufenes Einmalpasswort (B4): wie ein falsches Passwort, zählt als Fehlversuch.
        if ($user === null || !$user->isActive || !$verified || $user->passwordExpired($this->clock->now())) {
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
        $session = $this->sessions->start($user->id, $userAgent, $ip);
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

    /**
     * Bestätigt das Passwort des Handelnden für eine gefährliche Verwaltungsaktion (ADR 0005, E7): Admin-Zuweisung
     * vergeben/entziehen, Passwort-/2FA-Reset, Löschen. Dieselbe Sperre wie die Anmeldung; ein Fehlschlag zählt als
     * Fehlversuch und landet als `auth.reauth_failed` im Audit-Log.
     *
     * @throws TooManyAttempts wenn Konto oder IP gesperrt sind (nichts wird geprüft) → 429 mit Retry-After
     */
    public function confirmPassword(UserAccount $user, #[\SensitiveParameter] string $password, string $ip): bool
    {
        $reservation = $this->reserveOrFail($user, $ip);
        $ok = strlen($password) <= self::MAX_PASSWORD && $this->hasher->verify($password, $user->passwordHash)
            && $user->isActive && !$user->passwordExpired($this->clock->now());
        if (!$ok) {
            $this->fail($user, $reservation, 'auth.reauth_failed');

            return false;
        }
        $this->throttle->release($user->username, $ip);

        return true;
    }

    /**
     * Eigenes Passwort ändern (ADR 0005, E10), auch als Pflichtwechsel nach einem Einmalpasswort.
     *
     * Ablauf: neues Passwort prüfen (≤ 1024 Byte, Regeln von `PasswordHasher`; 422 ohne Wert) → Versuch reservieren →
     * aktuelles Passwort prüfen (Fehlschlag zählt, Audit `auth.password_change_failed`, Rückgabe null) → neues ≠ bisheriges
     * (422) → in **einer** Transaktion: Hash setzen, Pflichtwechsel und Ablauf löschen, `password_changed_at`, alle
     * Sitzungen beenden und die aktuelle durch eine neue ersetzen, Audit `user.password_changed`.
     *
     * @return Session|null die neue Sitzung (neues Cookie, neues CSRF-Token); null bei falschem aktuellem Passwort
     *
     * @throws TooManyAttempts  wenn Konto oder IP gesperrt sind
     * @throws ValidationFailed wenn das neue Passwort die Regeln verletzt (Feld `new_password`)
     */
    public function changeOwnPassword(
        UserAccount $user,
        Session $current,
        #[\SensitiveParameter] string $old,
        #[\SensitiveParameter] string $new,
        string $ip,
    ): ?Session {
        if ($current->userId !== $user->id) {
            throw new \LogicException('Passwortwechsel nur für das Konto der eigenen Sitzung.');
        }
        if (strlen($new) > self::MAX_PASSWORD) {
            throw ValidationFailed::field('new_password', self::MESSAGE_NEW_TOO_LONG);
        }
        if (!PasswordHasher::meetsPolicy($new)) {
            throw ValidationFailed::field('new_password', self::MESSAGE_NEW_TOO_SHORT);
        }

        $reservation = $this->reserveOrFail($user, $ip);
        $ok = strlen($old) <= self::MAX_PASSWORD && $this->hasher->verify($old, $user->passwordHash)
            && $user->isActive && !$user->passwordExpired($this->clock->now());
        if (!$ok) {
            $this->fail($user, $reservation, 'auth.password_change_failed');

            return null;
        }
        // Das aktuelle Passwort stimmt: der Versuch zählt nicht, auch wenn das neue gleich ist.
        $this->throttle->release($user->username, $ip);
        if ($this->hasher->verify($new, $user->passwordHash)) {
            throw ValidationFailed::field('new_password', self::MESSAGE_NEW_SAME);
        }

        $hash = $this->hasher->hash($new);
        $now = $this->clock->now();

        return $this->db->transaction(function () use ($user, $current, $hash, $now, $ip): Session {
            $this->users->setOwnPasswordHash($user->id, $hash, $now);
            $session = $this->sessions->replaceAll($current, $ip);
            $this->audit->record($user->id, 'user.password_changed', 'user:' . $user->id . ' ' . $user->username);

            return $session;
        });
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
