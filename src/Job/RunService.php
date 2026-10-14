<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Schedule\RunTrigger;
use Meridian\Security\AccessControl;
use Meridian\Security\AccessDenied;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;

/**
 * Manueller Lauf und Testlauf (docs/decisions/0003, E4): der Lauf wird nur eingereiht, der Scheduler-Prozess führt
 * ihn im nächsten Takt aus. Die Rechteprüfung beim Einreihen ist die erste Hälfte von H1; die zweite macht der
 * Worker beim Übernehmen mit frischen Rechten.
 *
 * Reihenfolge: Datensatz über `findVisible()` mit dem Ansichtsbereich (nicht sichtbar → null → 404) →
 * `require(jobs.run)` mit der gespeicherten Kategorie (→ 403) → Zustand (deaktiviert, offener Lauf, Kontingent) →
 * Lauf und Audit-Eintrag in **einer** sofort schreibenden Transaktion, damit zwei gleichzeitige Anfragen nicht
 * beide durch die Grenze kommen.
 */
final class RunService
{
    /** Höchstens so viele manuelle Läufe und Testläufe je Benutzer in der letzten Stunde. */
    public const MAX_PER_HOUR = 60;
    public const MAX_AUTOMATION_PER_HOUR = 360;

    public function __construct(
        private readonly Connection $db,
        private readonly JobRepository $jobs,
        private readonly AccessControl $access,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<RoleGrant> $grants
     * @param list<int> $chain
     *
     * @return int|null die Nummer des eingereihten Laufs; null = Job nicht sichtbar (→ 404)
     *
     * @throws AccessDenied|RunNotQueued
     */
    public function enqueue(int $userId, array $grants, int $jobId, RunTrigger $trigger, ?int $automationId = null, array $chain = []): ?int
    {
        if ($trigger !== RunTrigger::Manual && $trigger !== RunTrigger::Test) {
            throw new \InvalidArgumentException('Nur manuelle Läufe und Testläufe werden hier eingereiht.');
        }
        $viewScope = $this->access->scope($grants, Permission::ViewJobs);

        $work = function () use ($userId, $grants, $jobId, $trigger, $viewScope, $automationId, $chain): ?int {
            $job = $this->jobs->findVisible($jobId, $viewScope);
            if ($job === null) {
                return null;
            }
            // Die Kategorie kommt aus dem gespeicherten Job, nie aus der Anfrage.
            $this->access->require($grants, Permission::RunJobs, $job->categoryName);

            $now = $this->clock->now();
            if ($trigger === RunTrigger::Manual && !$job->isEnabled) {
                throw new RunNotQueued(RunRefusal::JobDisabled);
            }
            if ($this->recentCount($userId, $now, $automationId !== null) >= ($automationId === null ? self::MAX_PER_HOUR : self::MAX_AUTOMATION_PER_HOUR)) {
                throw new RunNotQueued(RunRefusal::RateLimited);
            }
            if ($this->hasOpenRun($jobId)) {
                throw new RunNotQueued(RunRefusal::AlreadyOpen);
            }

            $this->db->execute(
                "INSERT INTO runs (job_id, trigger, status, scheduled_for, attempt, started_by, automation_id, automation_chain) VALUES (:job, :trigger, 'queued', :scheduled, 1, :user, :automation, :chain)",
                ['job' => $jobId, 'trigger' => $trigger->value, 'scheduled' => Timestamp::format($now), 'user' => $userId, 'automation' => $automationId, 'chain' => json_encode($chain, JSON_THROW_ON_ERROR)],
            );
            $runId = $this->db->lastInsertId();
            $this->audit->record(
                $userId,
                $automationId !== null ? 'job.run_automation' : ($trigger === RunTrigger::Test ? 'job.run_test' : 'job.run_manual'),
                'job:' . $jobId . ' ' . $job->name . '; run:' . $runId,
            );

            return $runId;
        };
        return $this->db->inTransaction() ? $work() : $this->db->immediate($work);
    }

    private function hasOpenRun(int $jobId): bool
    {
        return $this->db->fetchOne(
            "SELECT 1 AS open_run FROM runs WHERE job_id = :job AND trigger IN ('manual', 'test') AND status IN ('queued', 'running') LIMIT 1",
            ['job' => $jobId],
        ) !== null;
    }

    private function recentCount(int $userId, \DateTimeImmutable $now, bool $automation = false): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS n FROM runs WHERE started_by = :user AND trigger IN ('manual', 'test') AND scheduled_for > :since AND ((:automation=1 AND automation_id IS NOT NULL) OR (:automation=0 AND automation_id IS NULL))",
            ['user' => $userId, 'since' => Timestamp::format($now->modify('-1 hour')), 'automation'=>(int)$automation],
        );

        return $row === null ? 0 : Row::int($row, 'n');
    }
}
