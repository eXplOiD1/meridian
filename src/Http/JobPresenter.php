<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Job\JobPermissions;
use Meridian\Job\JobRecord;
use Meridian\Job\LiveChunk;
use Meridian\Job\LastRun;
use Meridian\Job\RunRecord;
use Meridian\Runner\Http\UrlDisplay;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;
use Meridian\Security\SecretMasker;
use Meridian\Settings\HttpDisplay;

/**
 * Baut die JSON-Form von Jobs und Läufen (docs/decisions/0003, §4.3): eine Feld-Allowlist, nie der Datensatz.
 *
 * - Geheimnisse kommen nie vor. Von der Anfrage gibt es nur `target`, die beim Speichern erzeugte `display_url`
 *   (nur im Detail, E2) und `has_*`/Anzahl. Header-Namen und -Werte, Body und URL-Pfad stehen nie hier.
 * - `display_url`, `has_url`, `has_headers`, `header_count` und `has_body` nur für Benutzer, die den Job bearbeiten
 *   dürfen (`can.edit` mit der gespeicherten Kategorie, Entscheidung Alex 09.10.2026); alle anderen bekommen nur
 *   `has_request` und `target`.
 * - Beim Lesen werden `display_url` und `target` auf die aktuellen Einstellungen verschärft
 *   (`http.display_path`, `http.display_host`, {@see UrlDisplay::tightenStored()}); bei `display_host = hidden`
 *   zeigt `target` nur `https://••••`, für jede Rolle.
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
    public function summary(JobRecord $job, array $grants, HttpDisplay $display): array
    {
        return $this->base($job, $grants) + [
            'http' => $job->http === null ? null : ['target' => $this->text(UrlDisplay::target($job->http->target(), $display))],
        ];
    }

    /**
     * @param list<RoleGrant> $grants
     *
     * @return array<string, mixed>
     */
    public function detail(JobRecord $job, array $grants, HttpDisplay $display): array
    {
        $http = $job->http;
        if ($http === null) {
            return $this->base($job, $grants) + ['http' => null];
        }

        $data = [
            'method' => $http->method->value,
            'timeout_seconds' => $http->timeoutSeconds,
            'expected_status' => $http->expectedStatusText(),
            'max_redirects' => $http->maxRedirects,
            'store_response' => $http->storeResponse->value,
            'target' => $this->text(UrlDisplay::target($http->target(), $display)),
            'has_request' => true,
        ];
        // Mit der gespeicherten Kategorie, wie can.edit; ohne Bearbeitungsrecht nichts über die Anfrage.
        if ($this->canEdit($job, $grants)) {
            $data += [
                // Beim Speichern von UrlDisplay erzeugt; hier nur auf die aktuellen Einstellungen verschärft, nie
                // neu gebildet und nie entschlüsselt (E2).
                'display_url' => $this->text(UrlDisplay::tightenStored($http->displayUrl, $http->target(), $display)),
                'has_url' => true,
                'has_headers' => $http->hasHeaders,
                'header_count' => $http->headerCount,
                'has_body' => $http->hasBody,
            ];
        }

        return $this->base($job, $grants) + ['http' => $data];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(RunRecord $run, bool $withOutput, HttpDisplay $display): array
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
            $data['output'] = $run->output === null ? null : $this->text($display->hidesHost() ? UrlDisplay::hideHostInRunOutput($run->output) : $run->output);
        }
        $data['started_by'] = $run->startedById === null ? null : ['id' => $run->startedById, 'display_name' => $this->text($run->startedByName ?? '')];
        // Phase 4 (ADR 0004 §4.2). Nie `worker`, `heartbeat_at`, `exec_ref` (H2).
        $data['exit_code'] = $run->exitCode;
        $data['cancel_requested_at'] = $run->cancelRequestedAt;
        $data['cancelled_by'] = $run->cancelledById === null ? null : ['id' => $run->cancelledById, 'display_name' => $this->text($run->cancelledByName ?? '')];
        $data['output_bytes'] = $run->outputBytes;
        $data['live'] = $run->live;

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
                'edit' => $this->canEdit($job, $grants),
                'run' => $this->access->can($grants, Permission::RunJobs, $job->categoryName),
            ],
            'created_at' => $job->createdAt,
            'updated_at' => $job->updatedAt,
        ];
    }

    /**
     * @param list<RoleGrant> $grants
     */
    private function canEdit(JobRecord $job, array $grants): bool
    {
        return $this->access->can($grants, JobPermissions::edit($job->type), $job->categoryName);
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

    /**
     * Ein Stück des Live-Logs: beim Schreiben maskiert, hier **erneut** (Muster), als Text.
     *
     * @return array{seq: int, stream: string, text: string}
     */
    public function chunk(LiveChunk $chunk): array
    {
        return ['seq' => $chunk->seq, 'stream' => $chunk->stream->value, 'text' => $this->text($chunk->text)];
    }

    private function text(string $value): string
    {
        return $this->masker->mask($value);
    }
}
