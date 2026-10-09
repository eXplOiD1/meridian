<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Auth\AuditLog;
use Meridian\Database\Connection;
use Meridian\Schedule\Planner;
use Meridian\Security\AccessControl;
use Meridian\Security\AccessDenied;
use Meridian\Security\CategoryScope;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;

/**
 * Schreibende Operationen auf Jobs: anlegen, ändern, löschen, aktivieren und deaktivieren
 * (docs/decisions/0003, §4.1). Die eine Stelle, die Rechte prüft, Eingaben validiert, schreibt und das Audit-Log
 * führt; der Controller kümmert sich nur um HTTP.
 *
 * Reihenfolge je Operation: Datensatz über `findVisible()` (nicht sichtbar → null → 404) → `require()` mit der
 * gespeicherten Kategorie (→ AccessDenied → 403) → validieren (→ ValidationFailed → 422) → schreiben und auditieren
 * in **einer** sofort schreibenden Transaktion (Lesen, Entscheiden, Schreiben sind atomar) → erst nach dem Commit
 * `Planner::reschedule()` (H6: die Speicher-Transaktion hat `next_run_at` auf NULL gesetzt, der neue Termin wird
 * nach der Rechteprüfung und außerhalb der Transaktion berechnet, weil `reschedule()` selbst eine öffnet).
 *
 * Das Audit-Log bekommt nur Feldnamen, nie Werte, und nie die Anzeige-URL.
 */
final class JobService
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobRepository $jobs,
        private readonly JobValidator $validator,
        private readonly AccessControl $access,
        private readonly AuditLog $audit,
        private readonly Planner $planner,
    ) {
    }

    /**
     * @param list<RoleGrant>      $grants
     * @param array<mixed>         $input
     *
     * @throws AccessDenied|\Meridian\Http\ValidationFailed
     */
    public function create(int $userId, array $grants, #[\SensitiveParameter] array $input): ?JobRecord
    {
        $editScope = $this->access->scope($grants, Permission::EditHttpJobs);
        if ($editScope->isEmpty()) {
            // Ohne das Recht in irgendeiner Kategorie gibt es nichts zu validieren: 403, nicht 422.
            throw new AccessDenied(Permission::EditHttpJobs);
        }

        $id = $this->db->immediate(function () use ($userId, $grants, $input, $editScope): int {
            $draft = $this->validator->validate($input, $editScope, null);
            // Doppelt hält besser: das Recht in der Zielkategorie, auch wenn der Validator den Bereich schon kennt.
            $this->access->require($grants, Permission::EditHttpJobs, $draft->categoryName);
            $id = $this->jobs->insert($draft, $userId);
            $this->audit->record($userId, 'job.created', 'job:' . $id . ' ' . $draft->name);

            return $id;
        });
        $this->reschedule($id);

        return $this->jobs->findVisible($id, $editScope);
    }

    /**
     * @param list<RoleGrant> $grants
     * @param array<mixed>    $input
     *
     * @return JobRecord|null null = nicht sichtbar (→ 404)
     *
     * @throws AccessDenied|\Meridian\Http\ValidationFailed
     */
    public function update(int $userId, array $grants, int $id, #[\SensitiveParameter] array $input): ?JobRecord
    {
        $viewScope = $this->access->scope($grants, Permission::ViewJobs);
        $reset = $this->db->immediate(function () use ($userId, $grants, $id, $input, $viewScope): ?bool {
            $existing = $this->jobs->findVisible($id, $viewScope);
            if ($existing === null) {
                return null;
            }
            $permission = JobPermissions::edit($existing->type);
            $this->access->require($grants, $permission, $existing->categoryName);

            $draft = $this->validator->validate($input, $this->access->scope($grants, $permission), $existing);
            // Auch in der neuen Kategorie (A → B braucht das Recht in B).
            $this->access->require($grants, $permission, $draft->categoryName);

            $scheduleChanged = $draft->cron !== $existing->cron || $draft->timezone !== $existing->timezone || $draft->isEnabled !== $existing->isEnabled;
            $this->jobs->update($id, $draft, $scheduleChanged);
            $changed = self::changedFields($existing, $draft);
            if ($changed !== []) {
                $this->audit->record($userId, 'job.updated', 'job:' . $id . ' ' . $draft->name . '; geändert: ' . implode(', ', $changed));
            }

            return $scheduleChanged;
        });
        if ($reset === null) {
            return null;
        }
        if ($reset) {
            $this->reschedule($id);
        }

        return $this->jobs->findVisible($id, $viewScope);
    }

    /**
     * @param list<RoleGrant> $grants
     *
     * @return bool false = nicht sichtbar (→ 404)
     *
     * @throws AccessDenied
     */
    public function delete(int $userId, array $grants, int $id): bool
    {
        $viewScope = $this->access->scope($grants, Permission::ViewJobs);

        return $this->db->immediate(function () use ($userId, $grants, $id, $viewScope): bool {
            $existing = $this->jobs->findVisible($id, $viewScope);
            if ($existing === null) {
                return false;
            }
            $this->access->require($grants, JobPermissions::edit($existing->type), $existing->categoryName);
            $this->jobs->delete($id);
            $this->audit->record($userId, 'job.deleted', 'job:' . $id . ' ' . $existing->name);

            return true;
        });
    }

    /**
     * Aktiviert (neuer Termin ab jetzt) oder deaktiviert (kein Termin mehr, in einer Anweisung).
     *
     * @param list<RoleGrant> $grants
     *
     * @return JobRecord|null null = nicht sichtbar (→ 404)
     *
     * @throws AccessDenied
     */
    public function setEnabled(int $userId, array $grants, int $id, bool $enabled): ?JobRecord
    {
        $viewScope = $this->access->scope($grants, Permission::ViewJobs);
        $found = $this->db->immediate(function () use ($userId, $grants, $id, $enabled, $viewScope): bool {
            $existing = $this->jobs->findVisible($id, $viewScope);
            if ($existing === null) {
                return false;
            }
            $this->access->require($grants, JobPermissions::edit($existing->type), $existing->categoryName);
            $this->jobs->setEnabled($id, $enabled);
            $this->audit->record($userId, $enabled ? 'job.enabled' : 'job.disabled', 'job:' . $id . ' ' . $existing->name);

            return true;
        });
        if (!$found) {
            return null;
        }
        if ($enabled) {
            $this->reschedule($id);
        }

        return $this->jobs->findVisible($id, $viewScope);
    }

    /**
     * Berechnet den nächsten Termin. Der Job kann zwischen Commit und Aufruf gelöscht worden sein; dann gibt es
     * nichts zu planen.
     */
    private function reschedule(int $id): void
    {
        try {
            $this->planner->reschedule($id);
        } catch (\InvalidArgumentException) {
            // gelöscht: nichts zu tun
        }
    }

    /**
     * Namen der geänderten Felder für das Audit-Log: nie Werte. „Anfrage ersetzt“ steht für URL, Header und Body
     * zusammen, ohne zu sagen, was darin stand.
     *
     * @return list<string>
     */
    private static function changedFields(JobRecord $old, #[\SensitiveParameter] JobDraft $new): array
    {
        $fields = [];
        $add = static function (bool $changed, string $label) use (&$fields): void {
            if ($changed) {
                $fields[] = $label;
            }
        };
        $add($new->name !== $old->name, 'name');
        $add($new->cron !== $old->cron, 'cron');
        $add($new->timezone !== $old->timezone, 'timezone');
        $add($new->isEnabled !== $old->isEnabled, 'is_enabled');
        $add($new->catchUp !== $old->catchUp, 'catch_up');
        $add($new->overlapPolicy !== $old->overlapPolicy, 'overlap_policy');
        $add($new->retryCount !== $old->retryCount, 'retry_count');
        $add($new->retryDelaySeconds !== $old->retryDelaySeconds, 'retry_delay_seconds');
        $http = $old->http;
        if ($http !== null) {
            $add($new->http->method !== $http->method, 'http.method');
            $add($new->http->timeoutSeconds !== $http->timeoutSeconds, 'http.timeout_seconds');
            $add($new->http->expectedStatus !== $http->expectedStatus, 'http.expected_status');
            $add($new->http->maxRedirects !== $http->maxRedirects, 'http.max_redirects');
            $add($new->http->storeResponse !== $http->storeResponse, 'http.store_response');
        }
        if ($new->categoryId !== $old->categoryId) {
            $fields[] = 'Kategorie ' . ($old->categoryName ?? 'ohne') . ' → ' . ($new->categoryName ?? 'ohne');
        }
        if ($new->payload !== null) {
            $fields[] = 'Anfrage ersetzt';
        }

        return $fields;
    }
}
