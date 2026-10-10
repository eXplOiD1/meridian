<?php

declare(strict_types=1);

namespace Meridian\Shell;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Runner\Shell\DbShellTargetSource;
use Meridian\Runner\Shell\InfrastructureContainers;
use Meridian\Runner\Shell\ShellRules;
use Meridian\Runner\Shell\ShellTarget;
use Meridian\Runner\Shell\ShellTargetKind;

/**
 * Pflege der Ausführungsorte (Tabelle `shell_targets`, docs/decisions/0004 E4, §4.3). Nur für die Admin-API
 * (`shell.targets`) und die Befehlszeile; der Runner liest selbst über {@see DbShellTargetSource}.
 *
 * Jede neue Freigabe läuft durch {@see self::validate()}: Allowlist-Formate, ablehnen statt zurechtschneiden. Die
 * Kategorie muss existieren. Freigabe und Audit-Eintrag stehen in **einer** Transaktion.
 */
final class ShellTargetStore
{
    public const MAX_NOTE_LENGTH = 200;

    private const SELECT = 'SELECT t.id, t.kind, t.name, t.category_id, c.name AS category_name, t.users_json, t.default_user, t.note, t.created_at, u.display_name AS created_by
                  FROM shell_targets t
                  LEFT JOIN categories c ON c.id = t.category_id
                  LEFT JOIN users u ON u.id = t.created_by';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Prüft die Eingabe vollständig, bevor geschrieben wird.
     *
     * @param array<mixed> $users
     *
     * @throws InvalidShellTarget
     */
    public function validate(string $kind, string $name, ?int $categoryId, array $users, ?string $defaultUser, string $note): ShellTargetInput
    {
        $kindEnum = ShellTargetKind::tryFrom($kind);
        if ($kindEnum === null) {
            throw new InvalidShellTarget('kind', 'Art fehlt oder ist unbekannt. Erlaubt sind „docker“ (Container) und „host“ (Host-Profil).');
        }
        if (!$kindEnum->isValidName($name)) {
            throw new InvalidShellTarget('name', $kindEnum === ShellTargetKind::Docker
                ? 'Container-Name: 1–128 Zeichen aus Buchstaben, Ziffern, Unterstrich, Punkt und Bindestrich, mit einem Buchstaben oder einer Ziffer beginnend. Der Name muss genau stimmen.'
                : 'Profilname: 1–32 Zeichen aus Kleinbuchstaben, Ziffern und Bindestrich, mit einem Buchstaben beginnend.');
        }
        if ($kindEnum === ShellTargetKind::Docker && InfrastructureContainers::isInfrastructure($name)) {
            throw new InvalidShellTarget('name', InfrastructureContainers::MESSAGE);
        }
        if ($categoryId !== null && !$this->categoryExists($categoryId)) {
            throw new InvalidShellTarget('category_id', 'Kategorie unbekannt. Eine bestehende Kategorie wählen oder leer lassen (gilt dann global).');
        }
        if (mb_strlen($note, 'UTF-8') > self::MAX_NOTE_LENGTH || preg_match('//u', $note) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $note) === 1) {
            throw new InvalidShellTarget('note', 'Die Notiz darf höchstens 200 Zeichen lang sein und keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.');
        }

        $list = [];
        if ($kindEnum === ShellTargetKind::Host) {
            if ($users !== [] || $defaultUser !== null) {
                throw new InvalidShellTarget('users', 'Host-Profile haben keine Benutzerliste: „users“ leer und „default_user“ null lassen (der Benutzer ist fest meridian-run).');
            }
        } else {
            if ($users === [] || count($users) > ShellRules::MAX_USERS_PER_TARGET || !array_is_list($users)) {
                throw new InvalidShellTarget('users', 'Erlaubte Benutzer: Liste mit 1 bis 10 Einträgen.');
            }
            foreach ($users as $user) {
                if (!is_string($user) || !ShellRules::isValidUser($user)) {
                    throw new InvalidShellTarget('users', 'Benutzer: Name in Kleinbuchstaben (z. B. www-data) oder numerische ID (z. B. 1001 oder 1001:1001). Nichts wird zurechtgeschnitten.');
                }
                $list[] = $user;
            }
            if (count(array_unique($list)) !== count($list)) {
                throw new InvalidShellTarget('users', 'Ein Benutzer steht mehrfach in der Liste.');
            }
            if ($defaultUser === null || !in_array($defaultUser, $list, true)) {
                throw new InvalidShellTarget('default_user', 'Der Standardbenutzer ist Pflicht und muss in der Liste der erlaubten Benutzer stehen.');
            }
        }

        return new ShellTargetInput(new ShellTarget($kindEnum, $name), $categoryId, $list, $defaultUser, $note);
    }

    /**
     * Legt die Freigabe an und schreibt `shell.target_added` in **derselben** Transaktion: scheitert das Protokoll,
     * gibt es auch keine Freigabe (Schutz lockern nur mit Audit).
     *
     * @throws InvalidShellTarget doppelte Freigabe
     */
    public function add(ShellTargetInput $input, ?int $userId, AuditLog $audit): ShellTargetRecord
    {
        return $this->db->immediate(function () use ($input, $userId, $audit): ShellTargetRecord {
            $exists = $this->db->fetchOne(
                'SELECT id FROM shell_targets WHERE kind = :kind AND name = :name AND COALESCE(category_id, 0) = COALESCE(:category, 0)',
                ['kind' => $input->target->kind->value, 'name' => $input->target->name, 'category' => $input->categoryId],
            );
            if ($exists !== null) {
                throw new InvalidShellTarget('name', 'Diesen Ausführungsort gibt es für diesen Geltungsbereich schon (gleiche Art, gleicher Name, global oder gleiche Kategorie). Erst die alte Freigabe entfernen.');
            }
            $this->db->execute(
                'INSERT INTO shell_targets (kind, name, category_id, users_json, default_user, note, created_by, created_at)
                 VALUES (:kind, :name, :category, :users, :default, :note, :user, :at)',
                [
                    'kind' => $input->target->kind->value,
                    'name' => $input->target->name,
                    'category' => $input->categoryId,
                    'users' => json_encode($input->users, JSON_THROW_ON_ERROR),
                    'default' => $input->defaultUser,
                    'note' => $input->note,
                    'user' => $userId,
                    'at' => Timestamp::format($this->clock->now()),
                ],
            );
            $record = $this->find($this->db->lastInsertId());
            if ($record === null) {
                throw new \RuntimeException('Der Ausführungsort wurde gespeichert, ist aber nicht lesbar.');
            }
            $audit->record($userId, 'shell.target_added', $record->describe());

            return $record;
        });
    }

    /**
     * Ändert eine Freigabe (Name, Geltungsbereich, Benutzer, Standardbenutzer, Notiz; die Art nie) und schreibt
     * `shell.target_updated` (alt → neu) in **derselben** Transaktion. Null, wenn es die Freigabe nicht gibt.
     * Jobs, die auf den alten Ort oder Benutzer zeigen, lehnt der Runner ab dem nächsten Lauf ab
     * ({@see \Meridian\Runner\Shell\ShellTargetPolicy::check()}); `affected_jobs` zählt sie.
     *
     * @return array{record: ShellTargetRecord, affected_jobs: int}|null
     *
     * @throws InvalidShellTarget Art geändert oder doppelte Freigabe
     */
    public function update(int $id, ShellTargetInput $input, ?int $userId, AuditLog $audit): ?array
    {
        return $this->db->immediate(function () use ($id, $input, $userId, $audit): ?array {
            $old = $this->find($id);
            if ($old === null) {
                return null;
            }
            if ($old->target->kind !== $input->target->kind) {
                throw new InvalidShellTarget('kind', 'Die Art lässt sich nicht ändern. Den Ort entfernen und neu anlegen.');
            }
            $exists = $this->db->fetchOne(
                'SELECT id FROM shell_targets WHERE kind = :kind AND name = :name AND COALESCE(category_id, 0) = COALESCE(:category, 0) AND id <> :id',
                ['kind' => $input->target->kind->value, 'name' => $input->target->name, 'category' => $input->categoryId, 'id' => $id],
            );
            if ($exists !== null) {
                throw new InvalidShellTarget('name', 'Diesen Ausführungsort gibt es für diesen Geltungsbereich schon (gleiche Art, gleicher Name, global oder gleiche Kategorie).');
            }
            $this->db->execute(
                'UPDATE shell_targets SET name = :name, category_id = :category, users_json = :users, default_user = :default, note = :note WHERE id = :id',
                [
                    'name' => $input->target->name,
                    'category' => $input->categoryId,
                    'users' => json_encode($input->users, JSON_THROW_ON_ERROR),
                    'default' => $input->defaultUser,
                    'note' => $input->note,
                    'id' => $id,
                ],
            );
            $new = $this->find($id);
            if ($new === null) {
                throw new \RuntimeException('Der Ausführungsort wurde gespeichert, ist aber nicht lesbar.');
            }
            $hints = [];
            if ($old->users !== $new->users || $old->defaultUser !== $new->defaultUser) {
                $hints[] = 'Benutzer geändert';
            }
            if ($old->allowsRoot() !== $new->allowsRoot()) {
                $hints[] = $new->allowsRoot() ? 'root freigegeben' : 'root-Freigabe entfernt';
            }
            $audit->record($userId, 'shell.target_updated', '#' . $id . ' ' . $old->describe() . ' → ' . $new->describe() . ($hints === [] ? '' : ' [' . implode(', ', $hints) . ']'));

            return ['record' => $new, 'affected_jobs' => $this->jobsUsing($old)];
        });
    }

    /**
     * Zahl der Shell-Jobs, die auf diesen Ort zeigen (Art und Name; bei einer Kategorie nur Jobs dieser Kategorie).
     * Ohne Entschlüsselung: gelesen wird nur `config_json` (keine Geheimnisse).
     */
    public function jobsUsing(ShellTargetRecord $record): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS n FROM jobs
              WHERE type = 'shell'
                AND json_extract(config_json, '$.shell.target.kind') = :kind
                AND json_extract(config_json, '$.shell.target.name') = :name
                AND (:category IS NULL OR category_id = :category)",
            ['kind' => $record->target->kind->value, 'name' => $record->target->name, 'category' => $record->categoryId],
        );
        return isset($row['n']) && is_int($row['n']) ? $row['n'] : 0;
    }

    /**
     * Löscht eine Freigabe und schreibt `shell.target_removed` in derselben Transaktion; null, wenn es sie nicht
     * gibt (dann kein Audit-Eintrag). Jobs, die den Ort nutzen, schlagen ab dem nächsten Lauf fehl, nie fallen sie
     * auf „global“ zurück.
     */
    public function remove(int $id, ?int $userId, AuditLog $audit): ?ShellTargetRecord
    {
        return $this->db->immediate(function () use ($id, $userId, $audit): ?ShellTargetRecord {
            $record = $this->find($id);
            if ($record === null) {
                return null;
            }
            $this->db->execute('DELETE FROM shell_targets WHERE id = :id', ['id' => $id]);
            $audit->record($userId, 'shell.target_removed', $record->describe());

            return $record;
        });
    }

    /**
     * @return list<ShellTargetRecord>
     */
    public function all(): array
    {
        $records = [];
        foreach ($this->db->fetchAll(self::SELECT . ' ORDER BY t.id') as $row) {
            $record = self::record($row);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function find(int $id): ?ShellTargetRecord
    {
        return self::record($this->db->fetchOne(self::SELECT . ' WHERE t.id = :id', ['id' => $id]));
    }

    /**
     * Die Freigaben, die ein Job in dieser Kategorie nutzen darf (global oder genau diese; ohne Kategorie nur global).
     * Für den Editor (`GET /api/shell/targets`).
     *
     * @return list<ShellTargetRecord>
     */
    public function usableIn(?int $categoryId): array
    {
        $records = [];
        foreach ($this->db->fetchAll(
            self::SELECT . ' WHERE t.category_id IS NULL OR t.category_id = :category ORDER BY t.kind, t.name COLLATE NOCASE, t.id',
            ['category' => $categoryId],
        ) as $row) {
            $record = self::record($row);
            // Alte Freigaben für Meridians eigene Container (vor der Sperre angelegt) nie zur Auswahl anbieten.
            if ($record !== null && !($record->target->kind === ShellTargetKind::Docker && InfrastructureContainers::isInfrastructure($record->target->name))) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function categoryIdByName(string $name): ?int
    {
        $row = $this->db->fetchOne('SELECT id FROM categories WHERE name = :name', ['name' => $name]);

        return isset($row['id']) && is_int($row['id']) ? $row['id'] : null;
    }

    public function categoryExists(int $id): bool
    {
        return $this->db->fetchOne('SELECT 1 AS found FROM categories WHERE id = :id', ['id' => $id]) !== null;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function record(?array $row): ?ShellTargetRecord
    {
        if ($row === null) {
            return null;
        }
        $kind = isset($row['kind']) && is_string($row['kind']) ? ShellTargetKind::tryFrom($row['kind']) : null;
        $name = $row['name'] ?? null;
        if ($kind === null || !is_string($name)) {
            return null;
        }
        $target = new ShellTarget($kind, $name);
        $grant = DbShellTargetSource::grant($target, $row);
        $categoryName = $row['category_name'] ?? null;
        $note = $row['note'] ?? null;
        $createdAt = $row['created_at'] ?? null;
        $createdBy = $row['created_by'] ?? null;
        if ($grant === null || ($categoryName !== null && !is_string($categoryName)) || !is_string($note) || !is_string($createdAt)
            || ($createdBy !== null && !is_string($createdBy))) {
            return null;
        }

        return new ShellTargetRecord($grant->id, $target, $grant->categoryId, $categoryName, $grant->users, $grant->defaultUser, $note, $createdAt, $createdBy);
    }
}
