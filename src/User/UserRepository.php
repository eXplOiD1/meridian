<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Job\Row;
use Meridian\Security\PasswordHasher;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;

final class UserRepository
{
    private const EFFECTIVE_GRANTS_SQL = 'SELECT ur.id AS user_role_id, ur.all_categories, r.name AS role, rp.permission
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN users u ON u.id = ur.user_id
              WHERE ur.user_id = :id AND u.is_active = 1 AND u.deleted_at IS NULL AND u.password_must_change = 0';

    private const ASSIGNED_GRANTS_SQL = 'SELECT ur.id AS user_role_id, ur.all_categories, r.name AS role, rp.permission
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
               JOIN role_permissions rp ON rp.role_id = r.id
              WHERE ur.user_id = :id';

    private const SUMMARY_SQL = 'SELECT u.id, u.username, u.display_name, u.is_active, u.deleted_at, u.totp_enabled, u.password_must_change, u.last_login_at, u.created_at
       FROM users u
      WHERE (:id IS NULL OR u.id = :id)
        AND (:status IS NULL OR CASE WHEN u.deleted_at IS NOT NULL THEN \'deleted\' WHEN u.is_active = 1 THEN \'active\' ELSE \'inactive\' END = :status)
      ORDER BY u.username COLLATE NOCASE, u.id';

    private const ASSIGNMENT_ROWS_SQL = 'SELECT ur.id AS user_role_id, ur.user_id, ur.all_categories, r.id AS role_id, r.name AS role
       FROM user_roles ur JOIN roles r ON r.id = ur.role_id
      WHERE (:id IS NULL OR ur.user_id = :id)
      ORDER BY ur.user_id, r.id';

    private const ASSIGNMENT_CATEGORY_ROWS_SQL = 'SELECT urc.user_role_id, c.id AS category_id, c.name
       FROM user_role_categories urc
       JOIN user_roles ur ON ur.id = urc.user_role_id
       JOIN categories c ON c.id = urc.category_id
      WHERE (:id IS NULL OR ur.user_id = :id)
      ORDER BY c.name COLLATE NOCASE, c.id';

    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $hasher,
    ) {
    }

    public function create(string $username, string $displayName, #[\SensitiveParameter] string $password, string $role): int
    {
        if (preg_match('/^[a-z0-9._-]{2,64}$/i', $username) !== 1) {
            throw new \InvalidArgumentException('Benutzername: 2 bis 64 Zeichen, nur Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich.');
        }

        $roleRow = $this->db->fetchOne('SELECT id FROM roles WHERE name = :name', ['name' => $role]);
        if ($roleRow === null || !isset($roleRow['id']) || !is_int($roleRow['id'])) {
            throw new \InvalidArgumentException('Unbekannte Rolle.');
        }
        $roleId = $roleRow['id'];
        $hash = $this->hasher->hash($password);

        return $this->db->transaction(function (Connection $db) use ($username, $displayName, $hash, $roleId): int {
            $db->execute(
                'INSERT INTO users (username, display_name, password_hash, created_at) VALUES (:u, :d, :h, :c)',
                ['u' => $username, 'd' => $displayName, 'h' => $hash, 'c' => gmdate('c')],
            );
            $userId = $db->lastInsertId();
            $db->execute(
                // Ausdrücklich uneingeschränkt: so legt die Befehlszeile (Admin, Operator ...) Zuweisungen an.
                'INSERT INTO user_roles (user_id, role_id, all_categories) VALUES (:u, :r, 1)',
                ['u' => $userId, 'r' => $roleId],
            );

            return $userId;
        });
    }

    /** Findet auch inaktive und gelöschte Benutzer; wer das Konto benutzt, prüft `isActive`. */
    public function findByUsername(string $username): ?UserAccount
    {
        return $this->account($this->db->fetchOne(
            'SELECT id, username, display_name, password_hash, is_active, password_must_change, password_expires_at, deleted_at FROM users WHERE username = :u',
            ['u' => $username],
        ));
    }

    /** Findet auch inaktive und gelöschte Benutzer; wer das Konto benutzt, prüft `isActive`. */
    public function findById(int $id): ?UserAccount
    {
        return $this->account($this->db->fetchOne(
            'SELECT id, username, display_name, password_hash, is_active, password_must_change, password_expires_at, deleted_at FROM users WHERE id = :id',
            ['id' => $id],
        ));
    }

    /**
     * Ersetzt nur den Hash (Rehash nach der Anmeldung, CLI `user:password`). Pflichtwechsel und Ablauf bleiben.
     */
    public function updatePasswordHash(int $userId, #[\SensitiveParameter] string $hash): void
    {
        $this->db->execute('UPDATE users SET password_hash = :h WHERE id = :id', ['h' => $hash, 'id' => $userId]);
    }

    /**
     * Setzt den Hash eines Einmalpassworts (ADR 0005, E6): Pflichtwechsel an, Ablauf gesetzt. Bis zum Wechsel hat der
     * Benutzer keine Rechte ({@see grantsFor()}). Der Klartext kommt hier nie an, nur der Argon2id-Hash.
     *
     * @return bool ob eine Zeile geändert wurde (false bei gelöschtem oder unbekanntem Benutzer)
     */
    public function setOneTimePasswordHash(int $userId, #[\SensitiveParameter] string $hash, \DateTimeImmutable $expiresAt): bool
    {
        return 1 === $this->db->execute(
            'UPDATE users SET password_hash = :h, password_must_change = 1, password_expires_at = :e WHERE id = :id AND deleted_at IS NULL',
            ['h' => $hash, 'e' => Timestamp::format($expiresAt), 'id' => $userId],
        );
    }

    /**
     * 2FA-Reset durch einen Administrator (ADR 0005, E8): Secret, Zähler und alle Wiederherstellungscodes eines nicht
     * gelöschten Benutzers löschen; das Passwort bleibt. Öffnet keine Transaktion (anders als `TwoFactor::disable()`),
     * der Aufrufer schließt das mit dem Beenden der Sitzungen und dem Audit-Eintrag in eine ein.
     *
     * @return bool false, wenn der Benutzer gelöscht oder unbekannt ist (nichts geändert)
     */
    public function clearTwoFactor(int $userId): bool
    {
        $changed = $this->db->execute(
            'UPDATE users SET totp_secret_enc = NULL, totp_enabled = 0, totp_last_step = NULL WHERE id = :id AND deleted_at IS NULL',
            ['id' => $userId],
        );
        if ($changed !== 1) {
            return false;
        }
        $this->db->execute('DELETE FROM recovery_codes WHERE user_id = :u', ['u' => $userId]);

        return true;
    }

    /**
     * Eigenes Passwort gesetzt (E10): Pflichtwechsel aus, Ablauf weg, Zeitpunkt vermerkt.
     */
    public function setOwnPasswordHash(int $userId, #[\SensitiveParameter] string $hash, \DateTimeImmutable $changedAt): void
    {
        $this->db->execute(
            'UPDATE users SET password_hash = :h, password_must_change = 0, password_expires_at = NULL, password_changed_at = :c WHERE id = :id AND deleted_at IS NULL',
            ['h' => $hash, 'c' => Timestamp::format($changedAt), 'id' => $userId],
        );
    }

    /** Gibt es den Benutzernamen schon (ohne Rücksicht auf Schreibung, auch gelöschte Konten)? */
    public function usernameTaken(string $username): bool
    {
        return $this->db->fetchOne('SELECT id FROM users WHERE username = :u', ['u' => $username]) !== null;
    }

    /**
     * Legt einen Benutzer mit Einmalpasswort an (E6): nur der Hash, Pflichtwechsel an, Ablauf gesetzt, keine Rechte
     * bis zum Wechsel. Zuweisungen setzt der Aufrufer mit {@see replaceAssignments()}. Öffnet keine Transaktion.
     */
    public function insertWithOneTimePassword(string $username, string $displayName, #[\SensitiveParameter] string $hash, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): int
    {
        $this->db->execute(
            'INSERT INTO users (username, display_name, password_hash, password_must_change, password_expires_at, created_at) VALUES (:u, :d, :h, 1, :e, :c)',
            ['u' => $username, 'd' => $displayName, 'h' => $hash, 'e' => Timestamp::format($expiresAt), 'c' => Timestamp::format($now)],
        );

        return $this->db->lastInsertId();
    }

    /** Ändert den Anzeigenamen eines nicht gelöschten Benutzers. @return bool ob eine Zeile geändert wurde */
    public function updateDisplayName(int $userId, string $displayName): bool
    {
        return $this->db->execute('UPDATE users SET display_name = :d WHERE id = :id AND deleted_at IS NULL', ['d' => $displayName, 'id' => $userId]) === 1;
    }

    /** Aktiviert oder deaktiviert einen nicht gelöschten Benutzer. @return bool ob eine Zeile geändert wurde */
    public function setActive(int $userId, bool $active): bool
    {
        return $this->db->execute('UPDATE users SET is_active = :a WHERE id = :id AND deleted_at IS NULL', ['a' => $active ? 1 : 0, 'id' => $userId]) === 1;
    }

    /**
     * Soft-Delete (E5): Zeile, Benutzername und Anzeigename bleiben (Audit-Log und Verlauf behalten ihren Bezug); das
     * Passwort wird durch den Hash eines verworfenen Zufallswerts ersetzt, 2FA, Wiederherstellungscodes, API-Tokens und
     * Zuweisungen werden gelöscht. Sitzungen beendet der Aufrufer ({@see \Meridian\Auth\SessionManager::endAllForUser()}).
     * Öffnet keine Transaktion; der Aufrufer prüft danach `AdminInvariant`.
     *
     * @return bool false, wenn der Benutzer schon gelöscht war (nichts geändert)
     */
    public function markDeleted(int $userId, #[\SensitiveParameter] string $discardedHash, \DateTimeImmutable $now): bool
    {
        $changed = $this->db->execute(
            'UPDATE users SET deleted_at = :now, is_active = 0, password_hash = :h, password_must_change = 1, password_expires_at = NULL,
                    totp_secret_enc = NULL, totp_enabled = 0, totp_last_step = NULL
              WHERE id = :id AND deleted_at IS NULL',
            ['now' => Timestamp::format($now), 'h' => $discardedHash, 'id' => $userId],
        );
        if ($changed !== 1) {
            return false;
        }
        $this->db->execute('DELETE FROM recovery_codes WHERE user_id = :u', ['u' => $userId]);
        $this->db->execute('DELETE FROM api_tokens WHERE user_id = :u', ['u' => $userId]);
        $this->db->execute('DELETE FROM user_roles WHERE user_id = :u', ['u' => $userId]);

        return true;
    }

    /**
     * Ersetzt die Zuweisungen eines Benutzers **gezielt**: nicht mehr gewünschte Rollen werden gelöscht, neue angelegt,
     * geänderte Geltungsbereiche angepasst; nie wird die Tabelle geleert und neu befüllt. Die Reihenfolge beachtet die
     * Trigger aus Migration 0009 (erst Einzelkategorien entfernen, dann „alle“ setzen; erst „alle“ abschalten, dann
     * Kategorien eintragen). Öffnet keine Transaktion; der Aufrufer prüft danach `AdminInvariant`.
     *
     * @param list<Assignment> $assignments validiert (AssignmentValidator), Kategorien müssen existieren
     */
    public function replaceAssignments(int $userId, array $assignments): void
    {
        /** @var array<int, array{id: int, all: bool, categories: list<int>}> $existing Rollen-ID => Zeile */
        $existing = [];
        foreach ($this->db->fetchAll('SELECT id, role_id, all_categories FROM user_roles WHERE user_id = :u', ['u' => $userId]) as $row) {
            $rowId = Row::int($row, 'id');
            $categories = [];
            foreach ($this->db->fetchAll('SELECT category_id FROM user_role_categories WHERE user_role_id = :g', ['g' => $rowId]) as $categoryRow) {
                $categories[] = Row::int($categoryRow, 'category_id');
            }
            $existing[Row::int($row, 'role_id')] = ['id' => $rowId, 'all' => Row::bool($row, 'all_categories'), 'categories' => $categories];
        }

        $wanted = [];
        foreach ($assignments as $assignment) {
            $wanted[$assignment->roleId] = true;
        }
        foreach ($existing as $roleId => $row) {
            if (!isset($wanted[$roleId])) {
                $this->db->execute('DELETE FROM user_roles WHERE id = :id', ['id' => $row['id']]);
            }
        }

        foreach ($assignments as $assignment) {
            $current = $existing[$assignment->roleId] ?? null;
            if ($current === null) {
                $this->db->execute(
                    'INSERT INTO user_roles (user_id, role_id, all_categories) VALUES (:u, :r, :all)',
                    ['u' => $userId, 'r' => $assignment->roleId, 'all' => $assignment->allCategories ? 1 : 0],
                );
                $this->addCategories($this->db->lastInsertId(), $assignment->categoryIds);
                continue;
            }

            if ($assignment->allCategories) {
                if (!$current['all'] || $current['categories'] !== []) {
                    $this->db->execute('DELETE FROM user_role_categories WHERE user_role_id = :g', ['g' => $current['id']]);
                    $this->db->execute('UPDATE user_roles SET all_categories = 1 WHERE id = :g', ['g' => $current['id']]);
                }
                continue;
            }

            if ($current['all']) {
                $this->db->execute('UPDATE user_roles SET all_categories = 0 WHERE id = :g', ['g' => $current['id']]);
            }
            foreach (array_diff($current['categories'], $assignment->categoryIds) as $removed) {
                $this->db->execute('DELETE FROM user_role_categories WHERE user_role_id = :g AND category_id = :c', ['g' => $current['id'], 'c' => $removed]);
            }
            $this->addCategories($current['id'], array_values(array_diff($assignment->categoryIds, $current['categories'])));
        }
    }

    /**
     * @param list<int> $categoryIds
     */
    private function addCategories(int $userRoleId, array $categoryIds): void
    {
        foreach ($categoryIds as $categoryId) {
            $this->db->execute('INSERT INTO user_role_categories (user_role_id, category_id) VALUES (:g, :c)', ['g' => $userRoleId, 'c' => $categoryId]);
        }
    }

    public function markLogin(int $userId, \DateTimeImmutable $at): void
    {
        $this->db->execute('UPDATE users SET last_login_at = :at WHERE id = :id', ['at' => $at->format('c'), 'id' => $userId]);
    }

    public function hasUsers(): bool
    {
        return $this->db->fetchOne('SELECT id FROM users LIMIT 1') !== null;
    }

    /**
     * Liste für die Verwaltung (alle Zustände, nach Benutzername), optional auf einen Zustand begrenzt.
     * Liest nie Hash, Secret oder Codes.
     *
     * @return list<UserSummary>
     */
    public function summaries(?UserStatus $status = null): array
    {
        return $this->loadSummaries(null, $status);
    }

    /** Ein Benutzer für die Verwaltung, in jedem Zustand (auch gelöscht); null, wenn es die ID nicht gibt. */
    public function summary(int $id): ?UserSummary
    {
        return $this->loadSummaries($id, null)[0] ?? null;
    }

    /**
     * @return list<UserSummary>
     */
    private function loadSummaries(?int $id, ?UserStatus $status): array
    {
        $roleRows = [];
        foreach ($this->db->fetchAll(self::ASSIGNMENT_ROWS_SQL, ['id' => $id]) as $row) {
            $roleRows[Row::int($row, 'user_id')][] = $row;
        }
        $categoryRows = [];
        foreach ($this->db->fetchAll(self::ASSIGNMENT_CATEGORY_ROWS_SQL, ['id' => $id]) as $row) {
            $categoryRows[Row::int($row, 'user_role_id')][Row::int($row, 'category_id')] = Row::string($row, 'name');
        }

        $summaries = [];
        foreach ($this->db->fetchAll(self::SUMMARY_SQL, ['id' => $id, 'status' => $status?->value]) as $row) {
            $userId = Row::int($row, 'id');
            $assignments = [];
            foreach ($roleRows[$userId] ?? [] as $roleRow) {
                $categories = $categoryRows[Row::int($roleRow, 'user_role_id')] ?? [];
                $assignments[] = new AssignmentView(
                    Row::int($roleRow, 'role_id'),
                    Row::string($roleRow, 'role'),
                    // Dieselbe Lesart wie grantsFor(): „alle“ nur mit Flag und ohne Kategoriezeilen.
                    Row::bool($roleRow, 'all_categories') && $categories === [],
                    $categories,
                );
            }
            $summaries[] = new UserSummary(
                $userId,
                Row::string($row, 'username'),
                Row::string($row, 'display_name'),
                Row::stringOrNull($row, 'deleted_at') !== null ? UserStatus::Deleted : (Row::bool($row, 'is_active') ? UserStatus::Active : UserStatus::Inactive),
                Row::bool($row, 'totp_enabled'),
                // Unbekannter Wert ≠ 0: im Zweifel Pflichtwechsel (wie bei account()).
                Row::int($row, 'password_must_change') !== 0,
                Row::stringOrNull($row, 'last_login_at'),
                Row::string($row, 'created_at'),
                $assignments,
            );
        }

        return $summaries;
    }

    /**
     * Lädt die Rollen eines Benutzers inklusive Kategorie-Beschränkung. Die einzige Quelle für Rechte.
     *
     * Fail-closed: inaktive, gelöschte Benutzer und Benutzer mit offenem Pflicht-Passwortwechsel haben keine Rechte
     * (leere Liste). Eine Zuweisung ohne Flag und ohne Kategorien gewährt nichts; „alle“ nur über das Flag.
     *
     * @return list<RoleGrant>
     */
    public function grantsFor(int $userId): array
    {
        return $this->buildGrants($this->db->fetchAll(self::EFFECTIVE_GRANTS_SQL, ['id' => $userId]));
    }

    /**
     * Die **zugewiesenen** Rechte eines Benutzers, unabhängig von seinem Zustand (auch inaktiv, mit offenem
     * Pflichtwechsel). Nur für Vergleiche der Verwaltung (`GrantPolicy::mayManage()`): ein Benutzer mit
     * Einmalpasswort oder deaktiviert hat zurzeit keine wirksamen Rechte, die Zuweisung bleibt aber das, was ein
     * Verwalter selbst besitzen muss, um ihn zu verwalten. Nie für eine Rechteprüfung einer Anfrage benutzen.
     *
     * @return list<RoleGrant>
     */
    public function assignedGrantsFor(int $userId): array
    {
        return $this->buildGrants($this->db->fetchAll(self::ASSIGNED_GRANTS_SQL, ['id' => $userId]));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<RoleGrant>
     */
    private function buildGrants(array $rows): array
    {
        /** @var array<int, array{role: string, all: bool, permissions: list<Permission>}> $byGrant */
        $byGrant = [];
        foreach ($rows as $row) {
            $grantId = $row['user_role_id'] ?? null;
            $roleName = $row['role'] ?? null;
            $permission = isset($row['permission']) && is_string($row['permission']) ? Permission::tryFrom($row['permission']) : null;
            if (!is_int($grantId) || !is_string($roleName) || $permission === null) {
                continue;
            }
            $byGrant[$grantId] ??= ['role' => $roleName, 'all' => isset($row['all_categories']) && $row['all_categories'] === 1, 'permissions' => []];
            $byGrant[$grantId]['permissions'][] = $permission;
        }

        $grants = [];
        foreach ($byGrant as $grantId => $grant) {
            $categoryRows = $this->db->fetchAll(
                'SELECT c.name FROM user_role_categories urc JOIN categories c ON c.id = urc.category_id WHERE urc.user_role_id = :id',
                ['id' => $grantId],
            );
            $categories = [];
            foreach ($categoryRows as $categoryRow) {
                if (isset($categoryRow['name']) && is_string($categoryRow['name'])) {
                    $categories[] = $categoryRow['name'];
                }
            }
            // „Alle Kategorien“ nur mit dem ausdrücklichen Flag. Eine leere Liste (auch nach dem Löschen der
            // letzten Kategorie) erlaubt nichts.
            // Gibt es Kategoriezeilen, gilt die Zuweisung als beschränkt, auch wenn das Flag gesetzt ist: im Zweifel
            // die engere Lesart. Nur Flag UND keine Zeilen heißt „alle“.
            $grants[] = new RoleGrant($grant['role'], $grant['permissions'], $grant['all'] && $categories === [] ? null : $categories);
        }

        return $grants;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function account(?array $row): ?UserAccount
    {
        if ($row === null || !isset($row['id'], $row['username'], $row['display_name'], $row['password_hash'], $row['is_active'], $row['password_must_change'])
            || !is_int($row['id']) || !is_string($row['username']) || !is_string($row['display_name'])
            || !is_string($row['password_hash']) || !is_int($row['is_active']) || !is_int($row['password_must_change'])) {
            return null;
        }
        $expires = $row['password_expires_at'] ?? null;
        $deleted = $row['deleted_at'] ?? null;
        if (($expires !== null && !is_string($expires)) || ($deleted !== null && !is_string($deleted))) {
            return null;
        }

        return new UserAccount(
            $row['id'],
            $row['username'],
            $row['display_name'],
            $row['password_hash'],
            // Gelöscht heißt inaktiv, auch wenn die Zeile etwas anderes sagt (der Trigger verhindert das ohnehin).
            $row['is_active'] === 1 && $deleted === null,
            // Unbekannter Wert ≠ 0: im Zweifel Pflichtwechsel.
            $row['password_must_change'] !== 0,
            $expires,
            $deleted,
        );
    }
}
