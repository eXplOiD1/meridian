<?php

declare(strict_types=1);

namespace Meridian\Category;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Job\CategoryName;
use Meridian\Job\Row;

/**
 * Kategorien anlegen, umbenennen, löschen (ADR 0005 E9). Eine Stelle für Web-API und CLI: dieselbe Namensprüfung,
 * dieselbe Transaktion, derselbe Audit-Eintrag.
 *
 * - Jede Aktion läuft samt Audit in einer `immediate()`-Transaktion; scheitert der Audit-Eintrag, wird alles
 *   zurückgerollt.
 * - Löschen nur ohne Jobs (auch ohne deaktivierte). Die Fremdschlüssel entfernen danach `user_role_categories` und
 *   `http_internal_targets` der Kategorie (CASCADE): Zuweisungen verlieren die Kategorie, Freigaben verschwinden;
 *   nichts wird dadurch „alle“ oder „global“.
 * - Grenze für Benutzer: höchstens {@see HOURLY_LIMIT} Änderungen je Handelndem und Stunde, gezählt über das
 *   Audit-Log. Die CLI (Handelnder null) ist nicht begrenzt: ihre Vertrauensgrenze ist der Betriebssystem-Benutzer.
 */
final class CategoryService
{
    public const HOURLY_LIMIT = 60;

    private const IMPACT_SQL = 'SELECT c.id, c.name, c.notify_recipients,
        (SELECT COUNT(*) FROM jobs j WHERE j.category_id = c.id) AS jobs,
        (SELECT COUNT(*) FROM user_role_categories a WHERE a.category_id = c.id) AS assignments,
        (SELECT COUNT(*) FROM user_role_categories a WHERE a.category_id = c.id
            AND (SELECT COUNT(*) FROM user_role_categories o WHERE o.user_role_id = a.user_role_id) = 1) AS ineffective,
        (SELECT COUNT(*) FROM http_internal_targets t WHERE t.category_id = c.id) AS internal_targets,
        (SELECT COUNT(*) FROM monitors m WHERE m.category_id = c.id) + (SELECT COUNT(*) FROM status_pages s WHERE s.category_id = c.id) AS watchers
      FROM categories c
      WHERE (:id IS NULL OR c.id = :id)
      ORDER BY c.name COLLATE NOCASE, c.id';

    private const RECENT_SQL = "SELECT COUNT(*) AS n FROM audit_log
      WHERE user_id = :user AND action IN ('category.created', 'category.renamed', 'category.deleted', 'category.recipients_changed') AND created_at > :since";

    public function __construct(
        private readonly Connection $db,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Alle Kategorien mit ihren Folgen, nach Name sortiert.
     *
     * @return list<CategoryImpact>
     */
    public function overview(): array
    {
        return $this->load(null);
    }

    /** Folgen einer Kategorie, sonst null. */
    public function impact(int $id): ?CategoryImpact
    {
        return $this->load($id)[0] ?? null;
    }

    /** ID zur Kategorie mit diesem Namen (ohne Rücksicht auf Schreibung), sonst null. */
    public function idByName(string $name): ?int
    {
        $row = $this->db->fetchOne('SELECT id FROM categories WHERE name = :name COLLATE NOCASE', ['name' => $name]);

        return $row === null ? null : Row::int($row, 'id');
    }

    /**
     * @throws CategoryException
     */
    public function create(string $name, ?int $actorId): int
    {
        if (!CategoryName::isValid($name)) {
            throw CategoryException::invalidName();
        }

        return $this->db->immediate(function () use ($name, $actorId): int {
            $this->guardRate($actorId);
            if ($this->idByName($name) !== null) {
                throw CategoryException::nameTaken();
            }
            $this->db->execute('INSERT INTO categories (name) VALUES (:name)', ['name' => $name]);
            $id = $this->db->lastInsertId();
            $this->audit->record($actorId, 'category.created', 'category:' . $id . ' ' . $name);

            return $id;
        });
    }

    /**
     * Benennt um. Gleicher Name wie bisher ändert nichts (kein Audit-Eintrag). Zuweisungen, Freigaben und Jobs
     * hängen an der ID und bleiben.
     *
     * @throws CategoryException
     */
    public function rename(int $id, string $name, ?int $actorId): void
    {
        if (!CategoryName::isValid($name)) {
            throw CategoryException::invalidName();
        }

        $this->db->immediate(function () use ($id, $name, $actorId): void {
            $this->guardRate($actorId);
            $current = $this->impact($id) ?? throw CategoryException::notFound();
            if ($current->name === $name) {
                return;
            }
            $other = $this->idByName($name);
            if ($other !== null && $other !== $id) {
                throw CategoryException::nameTaken();
            }
            if ($this->db->execute('UPDATE categories SET name = :name WHERE id = :id', ['name' => $name, 'id' => $id]) !== 1) {
                throw CategoryException::notFound();
            }
            $this->audit->record($actorId, 'category.renamed', 'category:' . $id . ' ' . $current->name . ' → ' . $name);
        });
    }

    /** @param list<string> $recipients */
    public function setRecipients(int $id, array $recipients, int $actorId): void
    {
        $recipients = \Meridian\Notify\Recipients::validate($recipients, 'recipients');
        $this->db->immediate(function () use ($id, $recipients, $actorId): void {
            $this->guardRate($actorId);
            $current = $this->impact($id) ?? throw CategoryException::notFound();
            if ($current->recipients === $recipients) {
                return;
            }
            $this->db->execute('UPDATE categories SET notify_recipients = :recipients WHERE id = :id', ['recipients' => json_encode($recipients, JSON_THROW_ON_ERROR), 'id' => $id]);
            $this->audit->record($actorId, 'category.recipients_changed', 'category:' . $id);
        });
    }

    /**
     * Löscht die Kategorie, wenn kein Job an ihr hängt.
     *
     * @throws CategoryException
     */
    public function delete(int $id, ?int $actorId): CategoryImpact
    {
        return $this->db->immediate(function () use ($id, $actorId): CategoryImpact {
            $this->guardRate($actorId);
            $impact = $this->impact($id) ?? throw CategoryException::notFound();
            if ($impact->jobs > 0) {
                throw CategoryException::hasJobs($impact->jobs);
            }
            if ($impact->watchers > 0) {
                throw CategoryException::hasWatchers($impact->watchers);
            }
            if ($this->db->execute('DELETE FROM categories WHERE id = :id', ['id' => $id]) !== 1) {
                throw CategoryException::notFound();
            }
            $this->audit->record(
                $actorId,
                'category.deleted',
                'category:' . $id . ' ' . $impact->name . ' (Zuweisungen: ' . $impact->assignments . ', Freigaben: ' . $impact->internalTargets . ')',
            );

            return $impact;
        });
    }

    /**
     * @return list<CategoryImpact>
     */
    private function load(?int $id): array
    {
        $list = [];
        foreach ($this->db->fetchAll(self::IMPACT_SQL, ['id' => $id]) as $row) {
            $list[] = new CategoryImpact(
                Row::int($row, 'id'),
                Row::string($row, 'name'),
                Row::int($row, 'jobs'),
                Row::int($row, 'assignments'),
                Row::int($row, 'ineffective'),
                Row::int($row, 'internal_targets'),
                Row::int($row, 'watchers'),
                \Meridian\Notify\Recipients::decode(Row::string($row, 'notify_recipients')),
            );
        }

        return $list;
    }

    /** Muss innerhalb der Transaktion laufen, damit zwei gleichzeitige Änderungen die Grenze nicht umgehen. */
    private function guardRate(?int $actorId): void
    {
        if ($actorId === null) {
            return;
        }
        $row = $this->db->fetchOne(self::RECENT_SQL, [
            'user' => $actorId,
            'since' => $this->clock->now()->modify('-1 hour')->format('c'),
        ]);
        if ($row !== null && Row::int($row, 'n') >= self::HOURLY_LIMIT) {
            throw CategoryException::tooMany();
        }
    }
}
