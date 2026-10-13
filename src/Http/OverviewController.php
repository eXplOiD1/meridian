<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\Clock;
use Meridian\Database\Timestamp;
use Meridian\Job\JobRecord;
use Meridian\Job\JobRepository;
use Meridian\Job\JobSort;
use Meridian\Job\OverviewRepository;
use Meridian\Monitor\MonitorRepository;
use Meridian\Schedule\CronSchedule;
use Meridian\Schedule\RunStatus;
use Meridian\Security\AccessControl;
use Meridian\Security\AccessDenied;
use Meridian\Security\Permission;
use Meridian\Security\SecretMasker;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/overview?from=<ISO>&to=<ISO>` — Daten für „Heute im Takt“ (Übersicht): je sichtbarem Job die Läufe des
 * Zeitfensters in Abschnitten von {@see self::BUCKET_SECONDS} Sekunden (erfolgreich/Fehler), die noch geplanten
 * Abschnitte bis zum Ende des Fensters, dazu Summen und die Jobs, deren letzter Lauf fehlschlug.
 *
 * Nur mit Sicht `jobs.view` (leerer Bereich → 403), auf die erlaubten Kategorien gekappt wie Liste und Verlauf.
 * Das Fenster wählt der Browser (lokaler Tag), höchstens {@see self::MAX_WINDOW_SECONDS}. Namen maskiert, keine
 * Notizen, Ausgaben oder Worker-Daten.
 */
final class OverviewController
{
    public const BUCKET_SECONDS = 300;
    public const MAX_WINDOW_SECONDS = 26 * 3600;
    public const MAX_JOBS = 30;
    public const SLOW_MIN_EXTRA_SECONDS = 30;

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly JobRepository $jobs,
        private readonly OverviewRepository $overview,
        private readonly Clock $clock,
        private readonly SecretMasker $masker,
        private readonly ?MonitorRepository $monitors = null,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('overview', '/api/overview', $this->show(...));
    }

    public function show(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        $window = self::window($request);
        $grants = $this->users->grantsFor($session->userId);
        $scope = $this->access->scope($grants, Permission::ViewJobs);
        if ($scope->isEmpty()) {
            throw new AccessDenied(Permission::ViewJobs);
        }

        $from = $window[0];
        $to = $window[1];
        $now = $this->clock->now();
        $bucketCount = intdiv($to - $from + self::BUCKET_SECONDS - 1, self::BUCKET_SECONDS);
        $nowBucket = $now->getTimestamp() < $from ? -1 : intdiv($now->getTimestamp() - $from, self::BUCKET_SECONDS);

        $byJob = [];
        foreach ($this->overview->buckets($scope, $from, $to, self::BUCKET_SECONDS) as $row) {
            $byJob[$row['job_id']][] = [$row['bucket'], $row['ok'], $row['bad']];
        }

        $listing = $this->jobs->list($scope, null, null, false, JobSort::NextRun);
        $rows = [];
        $attention = [];
        $names = [];
        foreach ($listing->jobs as $job) {
            $names[$job->id] = $job->name;
        }
        foreach ($this->overview->runningWithUsualDuration($scope) as $running) {
            $elapsed = $now->getTimestamp() - Timestamp::parse($running['started_at'])->getTimestamp();
            if (isset($names[$running['job_id']]) && self::isSlow($elapsed * 1000, $running['usual_ms'])) {
                $attention[] = [
                    'job' => ['id' => $running['job_id'], 'name' => $this->masker->mask($names[$running['job_id']])],
                    'kind' => 'job',
                    'status' => 'slow',
                    'finished_at' => null,
                    'running_seconds' => $elapsed,
                    'usual_seconds' => intdiv($running['usual_ms'], 1000),
                ];
            }
        }
        foreach ($listing->jobs as $job) {
            $last = $job->lastRun;
            if ($job->isEnabled && $last !== null && ($last->status === RunStatus::Failed || $last->status === RunStatus::Timeout)) {
                $attention[] = [
                    'job' => ['id' => $job->id, 'name' => $this->masker->mask($job->name)],
                    'kind' => 'job',
                    'status' => $last->status->value,
                    'finished_at' => $last->finishedAt,
                    'running_seconds' => null,
                    'usual_seconds' => null,
                ];
            }
            $buckets = $byJob[$job->id] ?? [];
            $rows[] = [
                'id' => $job->id,
                'name' => $this->masker->mask($job->name),
                'type' => $job->type->value,
                'enabled' => $job->isEnabled,
                'buckets' => $buckets,
                'planned' => $job->isEnabled ? self::planned($job, $now, $from, $to, $nowBucket) : [],
                'next_run_at' => $job->nextRunAt,
            ];
            if (count($rows) >= self::MAX_JOBS) {
                break;
            }
        }

        // Überwachungen ohne Lebenszeichen (ADR 0011) in derselben Kappung wie die Jobs.
        $monitorRows = [];
        $monitorBuckets = $this->overview->monitorBuckets($scope, $from, $to, self::BUCKET_SECONDS);
        foreach ($this->monitors?->list($scope) ?? [] as $monitor) {
            if (count($monitorRows) < self::MAX_JOBS) {
                $monitorRows[] = ['id' => $monitor->id, 'name' => $this->masker->mask($monitor->name), 'enabled' => $monitor->isEnabled,
                    'buckets' => $monitorBuckets[$monitor->id] ?? [], 'planned' => [], 'type' => 'http', 'kind' => 'monitor'];
            }
            if ($monitor->isEnabled && $monitor->state === 'down') {
                $attention[] = [
                    'job' => ['id' => $monitor->id, 'name' => $this->masker->mask($monitor->name)],
                    'kind' => 'monitor',
                    'status' => 'down',
                    'finished_at' => $monitor->stateSince,
                    'running_seconds' => null,
                    'usual_seconds' => null,
                ];
            }
        }

        return JsonReply::json([
            'window' => [
                'from' => Timestamp::format((new \DateTimeImmutable('@' . $from))->setTimezone(new \DateTimeZone('UTC'))),
                'to' => Timestamp::format((new \DateTimeImmutable('@' . $to))->setTimezone(new \DateTimeZone('UTC'))),
                'bucket_seconds' => self::BUCKET_SECONDS,
                'buckets' => $bucketCount,
                'now_bucket' => $nowBucket,
            ],
            'jobs' => $rows,
            'monitors' => $monitorRows,
            'totals' => $this->overview->totals($scope, $from, $to),
            'attention' => array_slice($attention, 0, 10),
        ]);
    }

    /**
     * „Braucht länger als üblich“: mehr als das Doppelte des üblichen Werts und mindestens {@see self::SLOW_MIN_EXTRA_SECONDS} Sekunden mehr.
     */
    public static function isSlow(int $elapsedMs, int $usualMs): bool
    {
        return $elapsedMs > 2 * $usualMs && $elapsedMs - $usualMs >= self::SLOW_MIN_EXTRA_SECONDS * 1000;
    }

    /**
     * Noch geplante Abschnitte (Index) bis zum Ende des Fensters; springt je Treffer an das Ende des Abschnitts, so
     * dass auch ein Job mit jeder Minute höchstens so viele Schritte braucht wie das Fenster Abschnitte hat.
     *
     * @return list<int>
     */
    private static function planned(JobRecord $job, \DateTimeImmutable $now, int $from, int $to, int $nowBucket): array
    {
        try {
            $schedule = CronSchedule::forJob($job->cron, $job->timezone);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        $cursor = $now->getTimestamp() < $from ? new \DateTimeImmutable('@' . ($from - 1)) : $now;
        for ($i = 0; $i < 400; ++$i) {
            try {
                $next = $schedule->nextAfter($cursor);
            } catch (\Throwable) {
                break;
            }
            $ts = $next->getTimestamp();
            if ($ts >= $to) {
                break;
            }
            $bucket = intdiv($ts - $from, self::BUCKET_SECONDS);
            if ($bucket > $nowBucket && !in_array($bucket, $out, true)) {
                $out[] = $bucket;
            }
            $cursor = new \DateTimeImmutable('@' . ($from + ($bucket + 1) * self::BUCKET_SECONDS - 1));
        }

        return $out;
    }

    /**
     * @return array{int, int} Anfang und Ende (Unix-Sekunden)
     */
    private static function window(Request $request): array
    {
        $errors = [];
        $from = self::timestamp($request, 'from', $errors);
        $to = self::timestamp($request, 'to', $errors);
        if ($from !== null && $to !== null && ($to <= $from || $to - $from > self::MAX_WINDOW_SECONDS)) {
            $errors['to'] = 'Das Zeitfenster darf höchstens 26 Stunden umfassen und muss nach dem Anfang enden.';
        }
        if ($errors !== [] || $from === null || $to === null) {
            throw new ValidationFailed($errors);
        }

        return [$from, $to];
    }

    /**
     * @param array<string, string> $errors
     */
    private static function timestamp(Request $request, string $key, array &$errors): ?int
    {
        $raw = $request->query->all()[$key] ?? null;
        if (!is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $raw) !== 1) {
            $errors[$key] = 'Zeit im Format 2026-10-11T00:00:00Z (UTC) angeben.';

            return null;
        }
        try {
            return (new \DateTimeImmutable($raw))->getTimestamp();
        } catch (\Exception) {
            $errors[$key] = 'Ungültige Zeitangabe.';

            return null;
        }
    }
}
