<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Job\JobPermissions;
use Meridian\Job\JobRecord;
use Meridian\Job\LastRun;
use Meridian\Job\RunRecord;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;
use Meridian\Security\SecretMasker;

/**
 * Baut die JSON-Form von Jobs und Läufen (docs/decisions/0003, §4.3): eine Feld-Allowlist, nie der Datensatz.
 *
 * - Geheimnisse kommen nie vor. Von der Anfrage gibt es nur `target`, die beim Speichern erzeugte `display_url`
 *   (nur im Detail, E2) und `has_*`/Anzahl. Header-Namen und -Werte, Body und URL-Pfad stehen nie hier.
 * - `worker` und `heartbeat_at` eines Laufs erscheinen nie (H2).
 * - Jeder Text läuft vor der Ausgabe durch `SecretMasker::mask()`.
 */
final class JobPresenter
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly SecretMasker $masker,
    ) {
    }

    /**
     * @param list<RoleGrant> $grants
     *
     * @return array<string, mixed>
     */
    public function summary(JobRecord $job, array $grants): array
    {
        return $this->base($job, $grants) + [
            'http' => $job->http === null ? null : ['target' => $this->text($job->http->target())],
        ];
    }

    /**
     * @param list<RoleGrant> $grants
     *
     * @return array<string, mixed>
     */
    public function detail(JobRecord $job, array $grants): array
    {
        $http = $job->http;

        return $this->base($job, $grants) + [
            'http' => $http === null ? null : [
                'method' => $http->method->value,
                'timeout_seconds' => $http->timeoutSeconds,
                'expected_status' => $http->expectedStatusText(),
                'max_redirects' => $http->maxRedirects,
                'store_response' => $http->storeResponse->value,
                'target' => $this->text($http->target()),
                // Beim Speichern von UrlDisplay erzeugt und so abgelegt; hier nur ausgeben, nie neu bilden (E2).
                'display_url' => $this->text($http->displayUrl),
                'has_url' => true,
                'has_headers' => $http->hasHeaders,
                'header_count' => $http->headerCount,
                'has_body' => $http->hasBody,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(RunRecord $run, bool $withOutput): array
    {
        $data = [
            'id' => $run->id,
            'job_id' => $run->jobId,
            'trigger' => $run->trigger->value,
            'status' => $run->status->value,
            'attempt' => $run->attempt,
            'scheduled_for' => $run->scheduledFor,
            'started_at' => $run->startedAt,
            'finished_at' => $run->finishedAt,
            'duration_ms' => $run->durationMs,
            'http_status' => $run->httpStatus,
            'note' => $run->note === null ? null : $this->text($run->note),
        ];
        if ($withOutput) {
            $data['output'] = $run->output === null ? null : $this->text($run->output);
        }
        $data['started_by'] = $run->startedById === null ? null : ['id' => $run->startedById, 'display_name' => $this->text($run->startedByName ?? '')];

        return $data;
    }

    /**
     * @param list<RoleGrant> $grants
     *
     * @return array<string, mixed>
     */
    private function base(JobRecord $job, array $grants): array
    {
        return [
            'id' => $job->id,
            'name' => $this->text($job->name),
            'type' => $job->type->value,
            'category' => $job->categoryId === null ? null : ['id' => $job->categoryId, 'name' => $this->text($job->categoryName ?? '')],
            'owner' => $job->ownerId === null ? null : ['id' => $job->ownerId, 'display_name' => $this->text($job->ownerName ?? '')],
            'cron' => $job->cron,
            'timezone' => $job->timezone,
            'next_run_at' => $job->nextRunAt,
            'is_enabled' => $job->isEnabled,
            'overlap_policy' => $job->overlapPolicy->value,
            'retry_count' => $job->retryCount,
            'retry_delay_seconds' => $job->retryDelaySeconds,
            'catch_up' => $job->catchUp,
            'last_run' => $job->lastRun === null ? null : $this->lastRun($job->lastRun),
            'running' => $job->running,
            'can' => [
                'edit' => $this->access->can($grants, JobPermissions::edit($job->type), $job->categoryName),
                'run' => $this->access->can($grants, Permission::RunJobs, $job->categoryName),
            ],
            'created_at' => $job->createdAt,
            'updated_at' => $job->updatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastRun(LastRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'trigger' => $run->trigger->value,
            'finished_at' => $run->finishedAt,
            'duration_ms' => $run->durationMs,
            'http_status' => $run->httpStatus,
        ];
    }

    private function text(string $value): string
    {
        return $this->masker->mask($value);
    }
}
