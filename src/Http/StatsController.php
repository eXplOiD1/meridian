<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\Clock;
use Meridian\Job\StatsRepository;
use Meridian\Security\AccessControl;
use Meridian\Security\AccessDenied;
use Meridian\Security\Permission;
use Meridian\Security\SecretMasker;
use Meridian\Settings\Settings;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/stats?days=1..366[&job_id=]` — Läufe je Tag (lückenlos, UTC) und je Job für die letzten Tage (ADR 0008). Nur mit
 * Sicht `jobs.view` (leerer Bereich → 403), auf die erlaubten Kategorien gekappt; mit `job_id` nur Tage dieses Jobs (außerhalb
 * des Bereichs: leere Tage, nie ein Hinweis auf die Existenz). Jobnamen maskiert, keine Ausgaben, Notizen oder Worker-Daten.
 */
final class StatsController
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 366;
    public const MAX_JOBS = 50;

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly StatsRepository $stats,
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly SecretMasker $masker,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('stats', '/api/stats', $this->show(...));
    }

    public function show(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        $query = $request->query->all();
        $errors = [];
        $days = self::DEFAULT_DAYS;
        if (array_key_exists('days', $query)) {
            /** @var mixed $raw */
            $raw = $query['days'];
            if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,3}$/D', $raw) !== 1 || (int) $raw > self::MAX_DAYS) {
                $errors['days'] = 'Tage: ganze Zahl von 1 bis ' . self::MAX_DAYS . '.';
            } else {
                $days = (int) $raw;
            }
        }
        $jobId = null;
        if (array_key_exists('job_id', $query)) {
            /** @var mixed $raw */
            $raw = $query['job_id'];
            if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,17}$/D', $raw) !== 1) {
                $errors['job_id'] = 'Job: positive ganze Zahl.';
            } else {
                $jobId = (int) $raw;
            }
        }
        $grants = $this->users->grantsFor($session->userId);
        $scope = $this->access->scope($grants, Permission::ViewJobs);
        if ($scope->isEmpty()) {
            throw new AccessDenied(Permission::ViewJobs);
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        $today = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $from = $today->modify('-' . ($days - 1) . ' days');
        $fromDay = $from->format('Y-m-d');
        $byDay = $this->stats->daily($scope, $fromDay, $jobId);
        $daily = [];
        for ($i = 0; $i < $days; ++$i) {
            $day = $from->modify('+' . $i . ' days')->format('Y-m-d');
            $row = $byDay[$day] ?? ['runs' => 0, 'ok' => 0, 'failed' => 0, 'duration_ms' => 0];
            $daily[] = ['day' => $day, 'runs' => $row['runs'], 'ok' => $row['ok'], 'failed' => $row['failed'], 'avg_duration_ms' => $row['runs'] === 0 ? null : intdiv($row['duration_ms'], $row['runs'])];
        }

        $perJob = $this->stats->perJob($scope, $fromDay);
        if ($jobId !== null) {
            $perJob = array_intersect_key($perJob, [$jobId => true]);
        }
        uasort($perJob, static fn (array $a, array $b): int => $b['runs'] <=> $a['runs']);
        $jobs = [];
        foreach (array_slice($perJob, 0, self::MAX_JOBS, true) as $id => $row) {
            $jobs[] = [
                'id' => $id,
                'name' => $this->masker->mask($row['name']),
                'runs' => $row['runs'],
                'ok' => $row['ok'],
                'failed' => $row['failed'],
                'success_rate' => $row['runs'] === 0 ? null : round($row['ok'] * 100 / $row['runs'], 1),
                'avg_duration_ms' => $row['runs'] === 0 ? null : intdiv($row['duration_ms'], $row['runs']),
                'max_duration_ms' => $row['max_ms'],
            ];
        }

        return JsonReply::json([
            'range' => ['from' => $fromDay, 'to' => $today->format('Y-m-d'), 'days' => $days],
            'retention_days' => $this->settings->runRetentionDays(),
            'daily' => $daily,
            'jobs' => $jobs,
        ]);
    }
}
