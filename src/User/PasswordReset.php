<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Auth\LoginThrottle;
use Meridian\Auth\SessionManager;
use Meridian\Security\PasswordHasher;

/**
 * Setzt das Passwort eines bestehenden Benutzers neu (Notausgang an der Befehlszeile, z. B. bei vergessenem
 * Passwort). Alle Sitzungen des Benutzers enden, die Sperre nach Fehlversuchen wird aufgehoben, der Vorgang landet
 * im Audit-Log. Der zweite Faktor (2FA) bleibt unangetastet.
 *
 * Das gesetzte Passwort ist ein eigenes: Pflichtwechsel und Ablauf eines Einmalpassworts werden gelöscht,
 * `password_changed_at` wird gesetzt (ADR 0005 E6, Review 4a N2). Gelöschte Benutzer werden abgelehnt.
 */
final class PasswordReset
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly LoginThrottle $throttle,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return bool false, wenn es den Benutzer nicht gibt oder er gelöscht ist (nichts geändert)
     *
     * @throws \InvalidArgumentException bei zu kurzem Passwort
     */
    public function reset(string $username, #[\SensitiveParameter] string $password): bool
    {
        $user = $this->users->findByUsername($username);
        if ($user === null || $user->isDeleted()) {
            return false;
        }

        // Wirft bei zu kurzem Passwort, bevor irgendetwas geändert wird.
        $hash = $this->hasher->hash($password);
        if (!$this->users->setOwnPasswordHash($user->id, $hash, $this->clock->now())) {
            return false; // zwischendurch gelöscht
        }
        $this->sessions->endAllForUser($user->id);
        $this->throttle->unlockUser($user->username);
        $this->audit->record(null, 'user.password_reset', $user->username);

        return true;
    }
}
