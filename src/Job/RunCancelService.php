<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Schedule\RunStatus;
use Meridian\Security\AccessControl;
use Meridian\Security\AccessDenied;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;

/**
 * Lauf abbrechen (ADR 0004 E7), für alle Job-Typen.
 *
 * Reihenfolge: Lauf über `findVisible()` mit dem Ansichtsbereich (nicht sichtbar → null → 404) → `require(jobs.run)`
 * mit der Kategorie des gespeicherten Jobs (→ 403) → Zustand → Änderung und Audit `run.cancel_requested` in **einer**
 * sofort schreibenden Transaktion. Wartend → sofort `aborted`; laufend → `cancel_requested_*` setzen (nur beim ersten
 * Mal, dann auch nur ein Audit-Eintrag), der Worker sieht es über den Herzschlag; beendet → 409.
 */
final class RunCancelService
{
    public const NOTE_BEFORE_START = 'Abgebrochen vor dem Start.';

    public function __construct(
        private readonly Connection $db,
        private readonly RunRepository $runs,
        private readonly AccessControl $access,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<RoleGrant> $grants
     *
     * @return CancelOutcome|null null = Lauf nicht sichtbar (→ 404)
     *
     * @throws AccessDenied
     */
    public function cancel(int $userId, array $grants, int $runId): ?CancelOutcome
    {
        $viewScope = $this->access->scope($grants, Permission::ViewJobs);

        return $this->db->immediate(function () use ($userId, $grants, $runId, $viewScope): ?CancelOutcome {
            $run = $this->runs->findVisible($runId, $viewScope, withOutput: false);
            if ($run === null) {
                return null;
            }
            // Die Kategorie kommt aus dem gespeicherten Job, nie aus der Anfrage.
            $this->access->require($grants, Permission::RunJobs, $run->categoryName);
            $now = Timestamp::format($this->clock->now());
            $target = 'job:' . $run->jobId . ' ' . $run->jobName . '; run:' . $run->id;

            if ($run->status === RunStatus::Queued) {
                $changed = $this->db->execute(
                    "UPDATE runs SET status = 'aborted', finished_at = :now, note = :note, cancel_requested_at = :now, cancel_requested_by = :user WHERE id = :id AND status = 'queued'",
                    ['now' => $now, 'note' => self::NOTE_BEFORE_START, 'user' => $userId, 'id' => $run->id],
                );
                if ($changed === 1) {
                    $this->audit->record($userId, 'run.cancel_requested', $target . '; vor dem Start');

                    return CancelOutcome::AbortedBeforeStart;
                }
            }
            if ($run->status === RunStatus::Running) {
                $changed = $this->db->execute(
                    "UPDATE runs SET cancel_requested_at = :now, cancel_requested_by = :user WHERE id = :id AND status = 'running' AND cancel_requested_at IS NULL",
                    ['now' => $now, 'user' => $userId, 'id' => $run->id],
                );
                if ($changed === 1) {
                    $this->audit->record($userId, 'run.cancel_requested', $target);
                }

                return CancelOutcome::Requested;
            }

            return CancelOutcome::AlreadyFinished;
        });
    }
}
