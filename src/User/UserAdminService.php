<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Auth\AuditLog;
use Meridian\Auth\AuthService;
use Meridian\Auth\Clock;
use Meridian\Auth\LoginThrottle;
use Meridian\Auth\SessionManager;
use Meridian\Auth\TooManyAttempts;
use Meridian\Database\Connection;
use Meridian\Http\ValidationFailed;
use Meridian\Security\GrantPolicy;
use Meridian\Security\OneTimePassword;
use Meridian\Security\PasswordHasher;

/**
 * Benutzerverwaltung schreibend (ADR 0005, E3–E5, E7, §4.3–§4.6). Alle Rechtsprüfungen bauen auf
 * `AccessControl::can()` auf ({@see GrantPolicy}); der Aufrufer hat `users.manage` schon geprüft.
 *
 * Reihenfolge jeder Aktion (§4.3): Ziel laden (404) → gelöscht (409) → Selbstaktion (409) → `mayManage` (403) →
 * `mayAssign` je neue/geänderte Zuweisung (403) → Passwort-Bestätigung bei gefährlichen Aktionen (403/429) →
 * **eine** `Connection::immediate()`-Transaktion mit Schreiben, `AdminInvariant::assertHeld()` (409, Rollback) und
 * Audit-Eintrag (nie mit Passwort, Hash oder Einmalpasswort).
 */
final class UserAdminService
{
    /** Neue Benutzer je Handelndem und Stunde (§4.6). */
    public const CREATE_LIMIT = 30;
    /** Passwort-Resets und 2FA-Resets je Handelndem und Stunde, je Aktion gezählt (§4.6). */
    public const RESET_LIMIT = 20;
    private const WINDOW_SECONDS = 3600;

    private const COUNT_SQL = 'SELECT COUNT(*) AS n FROM audit_log WHERE user_id = :u AND action = :a AND created_at > :since';
    private const OLDEST_IN_WINDOW_SQL = 'SELECT created_at FROM audit_log WHERE user_id = :u AND action = :a AND created_at > :since
      ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET :offset';

    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly RoleCatalog $roles,
        private readonly GrantPolicy $policy,
        private readonly AdminInvariant $invariant,
        private readonly AuditLog $audit,
        private readonly AuthService $auth,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly Clock $clock,
        private readonly LoginThrottle $throttle,
    ) {
    }

    /**
     * Legt einen Benutzer mit Einmalpasswort an. Eine Admin-Zuweisung verlangt das Passwort des Handelnden.
     *
     * @param list<Assignment> $assignments
     *
     * @throws ValidationFailed     Benutzername vergeben, Passwort fehlt, Kategorie verschwunden
     * @throws UserRequestRefused   403 (Rechteausweitung, Passwort falsch), 429 (30 je Stunde)
     * @throws TooManyAttempts      Passwort-Bestätigung gesperrt
     */
    public function create(Actor $actor, string $username, string $displayName, array $assignments, #[\SensitiveParameter] ?string $currentPassword): CreatedUser
    {
        $dangerous = false;
        foreach ($assignments as $assignment) {
            $this->assertMayAssign($actor, $assignment);
            $dangerous = $dangerous || $this->isDangerousRole($assignment->roleId);
        }
        if ($dangerous) {
            $this->confirm($actor, $currentPassword);
        }

        // Das langsame Hashen vor der Transaktion: die Schreibsperre soll kurz bleiben.
        $password = OneTimePassword::generate();
        $hash = $this->hasher->hash($password->reveal());
        $now = $this->clock->now();
        $expiresAt = OneTimePassword::expiresAt($now);

        $id = $this->db->immediate(function () use ($actor, $username, $displayName, $assignments, $hash, $now, $expiresAt): int {
            $this->assertWithinLimit($actor, 'user.created', self::CREATE_LIMIT, 'Zu viele neue Benutzer in der letzten Stunde (höchstens ' . self::CREATE_LIMIT . '). Später erneut versuchen.');
            if ($this->users->usernameTaken($username)) {
                throw ValidationFailed::field('username', 'Benutzername ist vergeben.');
            }
            $id = $this->users->insertWithOneTimePassword($username, $displayName, $hash, $now, $expiresAt);
            $this->writeAssignments($id, $assignments);
            $created = $this->reload($id);
            $this->audit->record($actor->user->id, 'user.created', 'user:' . $id . ' ' . $username . ' (' . self::describe($created->assignments) . ')');

            return $id;
        });

        $user = $this->reload($id);

        return new CreatedUser($user, $password, $expiresAt);
    }

    /**
     * Ändert den Anzeigenamen (auch beim eigenen Konto erlaubt). Audit ohne Werte.
     */
    public function rename(Actor $actor, int $userId, string $displayName): UserSummary
    {
        $target = $this->load($userId);
        $this->assertMayManage($actor, $userId);

        return $this->db->immediate(function () use ($actor, $target, $displayName): UserSummary {
            if (!$this->users->updateDisplayName($target->id, $displayName)) {
                throw UserRequestRefused::deleted();
            }
            $this->audit->record($actor->user->id, 'user.renamed', 'user:' . $target->id . ' ' . $target->username . ': Anzeigename geändert');

            return $this->reload($target->id);
        });
    }

    /**
     * Ersetzt die ganze Liste der Zuweisungen. Kommt eine Rolle mit gefährlichem Recht (Admin) dazu oder fällt sie weg,
     * verlangt das das Passwort des Handelnden. Nie am eigenen Konto; `AdminInvariant` in derselben Transaktion.
     *
     * @param list<Assignment> $assignments
     *
     * @throws UserRequestRefused 404, 403, 409 (selbst, gelöscht, letzter Admin)
     * @throws ValidationFailed   Passwort fehlt
     */
    public function replaceAssignments(Actor $actor, int $userId, array $assignments, #[\SensitiveParameter] ?string $currentPassword): UserSummary
    {
        $target = $this->load($userId);
        $this->assertNotSelf($actor, $target);
        $this->assertMayManage($actor, $userId);

        $old = $this->reload($userId)->assignments;
        foreach ($assignments as $assignment) {
            if (!self::unchanged($assignment, $old)) {
                $this->assertMayAssign($actor, $assignment);
            }
        }
        $needsPassword = $this->dangerousRoleChanged($old, $assignments);
        if ($needsPassword) {
            $this->confirm($actor, $currentPassword);
        }

        return $this->db->immediate(function () use ($actor, $target, $assignments, $needsPassword): UserSummary {
            $current = $this->reload($target->id);
            if ($current->status === UserStatus::Deleted) {
                throw UserRequestRefused::deleted();
            }
            // Hat sich der Bestand seit der Passwort-Bestätigung so geändert, dass jetzt eine gefährliche Rolle
            // betroffen ist, die nicht bestätigt wurde: nichts schreiben (confirm() darf hier nicht laufen — die
            // Sperre öffnet eine eigene Transaktion).
            if (!$needsPassword && $this->dangerousRoleChanged($current->assignments, $assignments)) {
                throw UserRequestRefused::changedMeanwhile();
            }
            foreach ($assignments as $index => $assignment) {
                if ($assignment->categoryIds !== [] && count($this->roles->categoryNames($assignment->categoryIds)) !== count($assignment->categoryIds)) {
                    throw ValidationFailed::field('assignments.' . $index . '.category_ids', AssignmentValidator::MESSAGE_CATEGORY);
                }
            }

            $this->users->replaceAssignments($target->id, $assignments);
            $this->invariant->assertHeld();
            $changed = $this->reload($target->id);
            $this->audit->record(
                $actor->user->id,
                'user.assignments_changed',
                'user:' . $target->id . ' ' . $target->username . ': ' . self::describe($current->assignments) . ' → ' . self::describe($changed->assignments),
            );

            return $changed;
        });
    }

    /**
     * Deaktiviert (Sitzungen enden sofort) oder aktiviert einen Benutzer. Nie am eigenen Konto, nie bei gelöschten.
     * Ist er schon im gewünschten Zustand, ändert sich nichts (kein Audit-Eintrag).
     */
    public function setActive(Actor $actor, int $userId, bool $active): UserSummary
    {
        $target = $this->load($userId);
        $this->assertNotSelf($actor, $target);
        $this->assertMayManage($actor, $userId);

        return $this->db->immediate(function () use ($actor, $target, $active): UserSummary {
            $current = $this->reload($target->id);
            if ($current->status === UserStatus::Deleted) {
                throw UserRequestRefused::deleted();
            }
            if (($current->status === UserStatus::Active) !== $active) {
                if (!$this->users->setActive($target->id, $active)) {
                    throw UserRequestRefused::deleted();
                }
                if (!$active) {
                    $this->sessions->endAllForUser($target->id);
                }
                $this->invariant->assertHeld();
                $this->audit->record($actor->user->id, $active ? 'user.activated' : 'user.deactivated', 'user:' . $target->id . ' ' . $target->username);
            }

            return $this->reload($target->id);
        });
    }

    /**
     * Löschen als Soft-Delete (E5). Verlangt das Passwort des Handelnden; nie am eigenen Konto.
     *
     * @throws ValidationFailed Passwort fehlt
     */
    public function delete(Actor $actor, int $userId, #[\SensitiveParameter] ?string $currentPassword): void
    {
        $target = $this->load($userId);
        $this->assertNotSelf($actor, $target);
        $this->assertMayManage($actor, $userId);
        $this->confirm($actor, $currentPassword);

        // Ein Hash eines verworfenen Zufallswerts: eine spätere Reaktivierung per Datenbank belebt kein altes Passwort.
        $discarded = $this->hasher->hash(bin2hex(random_bytes(32)));
        $now = $this->clock->now();

        $this->db->immediate(function () use ($actor, $target, $discarded, $now): void {
            if (!$this->users->markDeleted($target->id, $discarded, $now)) {
                throw UserRequestRefused::deleted();
            }
            $this->sessions->endAllForUser($target->id);
            $this->invariant->assertHeld();
            $this->audit->record($actor->user->id, 'user.deleted', 'user:' . $target->id . ' ' . $target->username);
        });
    }

    /**
     * Admin-Passwort-Reset (E6, E7, S5): neues Einmalpasswort mit Pflichtwechsel und Ablauf, **alle** Sitzungen des
     * Ziels enden, die Sperre seines Benutzernamens wird aufgehoben, 2FA bleibt (B13). Verlangt das Passwort des
     * Handelnden; nie am eigenen Konto (dafür „Mein Konto“). Der Klartext steht nur im Rückgabewert.
     *
     * @throws UserRequestRefused 404, 409 (gelöscht, selbst, letzter Admin), 403 (mayManage, Passwort falsch), 429
     * @throws ValidationFailed   Passwort fehlt
     * @throws TooManyAttempts    Passwort-Bestätigung gesperrt
     */
    public function resetPassword(Actor $actor, int $userId, #[\SensitiveParameter] ?string $currentPassword): IssuedPassword
    {
        $target = $this->load($userId);
        $this->assertNotSelf($actor, $target);
        $this->assertMayManage($actor, $userId);
        $this->confirm($actor, $currentPassword);

        // Das langsame Hashen vor der Transaktion: die Schreibsperre soll kurz bleiben.
        $password = OneTimePassword::generate();
        $hash = $this->hasher->hash($password->reveal());
        $expiresAt = OneTimePassword::expiresAt($this->clock->now());

        $this->db->immediate(function () use ($actor, $target, $hash, $expiresAt): void {
            $this->assertWithinLimit($actor, 'user.password_reset', self::RESET_LIMIT, 'Zu viele Passwort-Resets in der letzten Stunde (höchstens ' . self::RESET_LIMIT . '). Später erneut versuchen.');
            if (!$this->users->setOneTimePasswordHash($target->id, $hash, $expiresAt)) {
                throw UserRequestRefused::deleted();
            }
            $this->sessions->endAllForUser($target->id);
            $this->throttle->unlockUser($target->username);
            // Mit dem Einmalpasswort hat das Ziel bis zum Wechsel keine Rechte: war es ein Admin, muss ein anderer bleiben.
            $this->invariant->assertHeld();
            $this->audit->record($actor->user->id, 'user.password_reset', 'user:' . $target->id . ' ' . $target->username);
        });

        return new IssuedPassword($password, $expiresAt);
    }

    /**
     * 2FA-Reset durch einen Administrator (E8): Secret, Zähler und Wiederherstellungscodes löschen, alle Sitzungen des
     * Ziels beenden, Sperre des Benutzernamens aufheben; das Passwort bleibt. Verlangt das Passwort des Handelnden; nie
     * am eigenen Konto. Auch ohne eingerichtete 2FA erlaubt (räumt eine begonnene Einrichtung mit ab).
     *
     * @throws UserRequestRefused 404, 409 (gelöscht, selbst), 403 (mayManage, Passwort falsch), 429
     * @throws ValidationFailed   Passwort fehlt
     * @throws TooManyAttempts    Passwort-Bestätigung gesperrt
     */
    public function resetTwoFactor(Actor $actor, int $userId, #[\SensitiveParameter] ?string $currentPassword): UserSummary
    {
        $target = $this->load($userId);
        $this->assertNotSelf($actor, $target);
        $this->assertMayManage($actor, $userId);
        $this->confirm($actor, $currentPassword);

        return $this->db->immediate(function () use ($actor, $target): UserSummary {
            $this->assertWithinLimit($actor, 'user.2fa_reset', self::RESET_LIMIT, 'Zu viele 2FA-Resets in der letzten Stunde (höchstens ' . self::RESET_LIMIT . '). Später erneut versuchen.');
            if (!$this->users->clearTwoFactor($target->id)) {
                throw UserRequestRefused::deleted();
            }
            $this->sessions->endAllForUser($target->id);
            $this->throttle->unlockUser($target->username);
            $this->audit->record($actor->user->id, 'user.2fa_reset', 'user:' . $target->id . ' ' . $target->username);

            return $this->reload($target->id);
        });
    }

    /**
     * Beendet alle Sitzungen eines anderen Benutzers (§4.2). Kein Passwort nötig (lockert nichts), aber `mayManage`;
     * nie am eigenen Konto (dafür „Mein Konto“ → andere Sitzungen beenden).
     *
     * @return int Anzahl beendeter Sitzungen
     *
     * @throws UserRequestRefused 404, 409 (gelöscht, selbst), 403
     */
    public function endSessions(Actor $actor, int $userId): int
    {
        $target = $this->load($userId);
        $this->assertNotSelf($actor, $target);
        $this->assertMayManage($actor, $userId);

        return $this->db->immediate(function () use ($actor, $target): int {
            if ($this->reload($target->id)->status === UserStatus::Deleted) {
                throw UserRequestRefused::deleted();
            }
            $ended = $this->sessions->endAllForUser($target->id);
            $this->audit->record($actor->user->id, 'user.sessions_ended', 'user:' . $target->id . ' ' . $target->username . ' (' . $ended . ' Sitzungen)');

            return $ended;
        });
    }

    private function reload(int $userId): UserSummary
    {
        return $this->users->summary($userId) ?? throw UserRequestRefused::notFound();
    }

    // --- Prüfungen -------------------------------------------------------------------------------

    /**
     * @throws UserRequestRefused 404; 409 bei gelöschtem Benutzer
     */
    private function load(int $userId): UserAccount
    {
        $target = $this->users->findById($userId) ?? throw UserRequestRefused::notFound();
        if ($target->isDeleted()) {
            throw UserRequestRefused::deleted();
        }

        return $target;
    }

    private function assertNotSelf(Actor $actor, UserAccount $target): void
    {
        if ($actor->user->id === $target->id) {
            throw UserRequestRefused::self();
        }
    }

    /**
     * Der Handelnde muss alles besitzen, was das Ziel zugewiesen hat — auch wenn das Ziel gerade inaktiv ist oder ein
     * Einmalpasswort hat (dann sind seine wirksamen Rechte leer, die Zuweisung zählt trotzdem).
     */
    private function assertMayManage(Actor $actor, int $userId): void
    {
        if (!$this->policy->mayManage($actor->grants, $this->users->assignedGrantsFor($userId))) {
            throw UserRequestRefused::mayNotManage();
        }
    }

    private function assertMayAssign(Actor $actor, Assignment $assignment): void
    {
        $role = $this->roles->find($assignment->roleId) ?? throw new \InvalidArgumentException('Rolle unbekannt.');
        $grant = $this->roles->grantFor($assignment);
        if (!$this->policy->mayAssign($actor->grants, $role->permissions, $grant->categories)) {
            throw UserRequestRefused::mayNotAssign();
        }
    }

    /**
     * Passwort des Handelnden bestätigen (E7). Fehlt es, ist das ein Feldfehler (422); ist es falsch, 403 ohne Hinweis
     * auf den Wert; bei Sperre wirft `AuthService` {@see TooManyAttempts} (Kernel: 429 mit `Retry-After`).
     *
     * @throws ValidationFailed
     * @throws UserRequestRefused
     * @throws TooManyAttempts
     */
    private function confirm(Actor $actor, #[\SensitiveParameter] ?string $currentPassword): void
    {
        if ($currentPassword === null) {
            throw ValidationFailed::field('current_password', 'Zur Bestätigung das eigene Passwort angeben.');
        }
        if (!$this->auth->confirmPassword($actor->user, $currentPassword, $actor->ip)) {
            throw UserRequestRefused::wrongPassword();
        }
    }

    private function isDangerousRole(int $roleId): bool
    {
        $role = $this->roles->find($roleId);

        return $role !== null && $role->isDangerous();
    }

    /**
     * Kommt eine Rolle mit gefährlichem Recht dazu oder fällt sie weg?
     *
     * @param list<AssignmentView> $old
     * @param list<Assignment>     $new
     */
    private function dangerousRoleChanged(array $old, array $new): bool
    {
        $before = [];
        foreach ($old as $view) {
            if ($this->isDangerousRole($view->roleId)) {
                $before[$view->roleId] = true;
            }
        }
        $after = [];
        foreach ($new as $assignment) {
            if ($this->isDangerousRole($assignment->roleId)) {
                $after[$assignment->roleId] = true;
            }
        }

        return array_diff_key($before, $after) !== [] || array_diff_key($after, $before) !== [];
    }

    /**
     * @param list<AssignmentView> $old
     */
    private static function unchanged(Assignment $new, array $old): bool
    {
        foreach ($old as $view) {
            if ($view->roleId !== $new->roleId) {
                continue;
            }
            $ids = array_keys($view->categories);
            $wanted = $new->categoryIds;
            sort($ids);
            sort($wanted);

            return $view->allCategories === $new->allCategories && $ids === $wanted;
        }

        return false;
    }

    /**
     * Höchstens $limit Einträge der Aktion je Handelndem und Stunde (aus dem Audit-Log gezählt, §4.6): innerhalb der
     * Schreibtransaktion, die Einträge entstehen mit der Aktion — es zählt nur, was wirklich passiert ist.
     *
     * @throws UserRequestRefused 429
     */
    private function assertWithinLimit(Actor $actor, string $action, int $limit, string $message): void
    {
        $now = $this->clock->now();
        $since = $now->modify('-' . self::WINDOW_SECONDS . ' seconds')->format('c');
        $params = ['u' => $actor->user->id, 'a' => $action, 'since' => $since];
        $count = $this->db->fetchOne(self::COUNT_SQL, $params);
        if ($count === null || !isset($count['n']) || !is_int($count['n']) || $count['n'] < $limit) {
            return;
        }

        // Frei wird ein Platz, wenn der $limit-neueste Eintrag aus dem Fenster fällt.
        $edge = $this->db->fetchOne(self::OLDEST_IN_WINDOW_SQL, $params + ['offset' => $limit - 1]);
        $retry = self::WINDOW_SECONDS;
        if ($edge !== null && isset($edge['created_at']) && is_string($edge['created_at'])) {
            $retry = max(1, (new \DateTimeImmutable($edge['created_at']))->getTimestamp() + self::WINDOW_SECONDS - $now->getTimestamp());
        }

        throw new UserRequestRefused(429, $message, $retry);
    }

    /**
     * @param list<Assignment> $assignments
     */
    private function writeAssignments(int $userId, array $assignments): void
    {
        foreach ($assignments as $index => $assignment) {
            if ($assignment->categoryIds !== [] && count($this->roles->categoryNames($assignment->categoryIds)) !== count($assignment->categoryIds)) {
                throw ValidationFailed::field('assignments.' . $index . '.category_ids', AssignmentValidator::MESSAGE_CATEGORY);
            }
        }
        $this->users->replaceAssignments($userId, $assignments);
    }

    /**
     * Für das Audit-Log: „Operator [NAS, Web], Beobachter [alle]“. Ohne Zuweisung „keine Rolle“.
     *
     * @param list<AssignmentView> $assignments
     */
    public static function describe(array $assignments): string
    {
        if ($assignments === []) {
            return 'keine Rolle';
        }
        $parts = [];
        foreach ($assignments as $view) {
            $scope = $view->allCategories ? 'alle' : ($view->categories === [] ? 'keine Kategorie' : implode(', ', $view->categories));
            $parts[] = $view->role . ' [' . $scope . ']';
        }

        return implode(', ', $parts);
    }
}
