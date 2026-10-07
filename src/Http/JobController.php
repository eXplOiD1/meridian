<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\Clock;
use Meridian\Auth\CsrfGuard;
use Meridian\Auth\Session;
use Meridian\Job\CategoryRepository;
use Meridian\Job\JobRepository;
use Meridian\Job\JobService;
use Meridian\Job\JobValidator;
use Meridian\Job\JobSort;
use Meridian\Job\RunRepository;
use Meridian\Runner\JobType;
use Meridian\Schedule\CronSchedule;
use Meridian\Schedule\RunStatus;
use Meridian\Security\AccessControl;
use Meridian\Security\AccessDenied;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;
use Meridian\Settings\Settings;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Job-API (docs/decisions/0003, §4): Jobs, Verlauf, Läufe, Kategorien, Vorschau und Grenzen.
 *
 * Ablauf jedes Endpunkts (mer-security §1): Sitzung → (bei ändernden Methoden CSRF) → Eingaben prüfen →
 * Datensatz über `findVisible()` mit dem Bereich des Rechts laden (nicht sichtbar → 404) →
 * `AccessControl::require()` mit der gespeicherten Kategorie (Recht fehlt → 403) → Aktion → Antwort über
 * den {@see JobPresenter} (Feld-Allowlist, maskiert, `no-store`).
 */
final class JobController
{
    private const DEFAULT_RUN_LIMIT = 50;
    private const MAX_PREVIEW = 10;
    private const DEFAULT_PREVIEW = 5;

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly JobRepository $jobs,
        private readonly RunRepository $runs,
        private readonly CategoryRepository $categories,
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly JobPresenter $presenter,
        private readonly JobService $service,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $id = ['id' => '[1-9][0-9]{0,17}'];
        $kernel->get('jobs_list', '/api/jobs', $this->list(...));
        $kernel->get('jobs_limits', '/api/jobs/limits', $this->limits(...));
        $kernel->get('jobs_show', '/api/jobs/{id}', $this->show(...), $id);
        $kernel->get('jobs_runs', '/api/jobs/{id}/runs', $this->history(...), $id);
        $kernel->get('runs_show', '/api/runs/{id}', $this->runDetail(...), $id);
        $kernel->get('schedule_preview', '/api/schedule/preview', $this->preview(...));
        $kernel->get('categories_list', '/api/categories', $this->categoryList(...));
        $kernel->post('jobs_create', '/api/jobs', $this->create(...));
        $kernel->put('jobs_update', '/api/jobs/{id}', $this->update(...), $id);
        $kernel->delete('jobs_delete', '/api/jobs/{id}', $this->delete(...), $id);
        $kernel->post('jobs_enable', '/api/jobs/{id}/enable', fn (Request $r): Response => $this->setEnabled($r, true), $id);
        $kernel->post('jobs_disable', '/api/jobs/{id}/disable', fn (Request $r): Response => $this->setEnabled($r, false), $id);
    }

    public function create(Request $request): Response
    {
        $auth = $this->authenticate($request, mutating: true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;

        $job = $this->service->create($session->userId, $grants, self::body($request));

        return $job === null
            ? JsonReply::error(404, 'Job nicht gefunden.')
            : JsonReply::json(['job' => $this->presenter->detail($job, $grants)], 201);
    }

    public function update(Request $request): Response
    {
        $auth = $this->authenticate($request, mutating: true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;

        $job = $this->service->update($session->userId, $grants, self::id($request), self::body($request));

        return $job === null
            ? JsonReply::error(404, 'Job nicht gefunden.')
            : JsonReply::json(['job' => $this->presenter->detail($job, $grants)]);
    }

    public function delete(Request $request): Response
    {
        $auth = $this->authenticate($request, mutating: true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;

        return $this->service->delete($session->userId, $grants, self::id($request))
            ? JsonReply::noContent()
            : JsonReply::error(404, 'Job nicht gefunden.');
    }

    private function setEnabled(Request $request, bool $enabled): Response
    {
        $auth = $this->authenticate($request, mutating: true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;

        $job = $this->service->setEnabled($session->userId, $grants, self::id($request), $enabled);

        return $job === null
            ? JsonReply::error(404, 'Job nicht gefunden.')
            : JsonReply::json(['job' => $this->presenter->detail($job, $grants)]);
    }

    public function list(Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [, $grants] = $auth;
        $scope = $this->access->scope($grants, Permission::ViewJobs);
        if ($scope->isEmpty()) {
            throw new AccessDenied(Permission::ViewJobs);
        }

        $query = $request->query->all();
        $errors = [];
        $type = null;
        if (array_key_exists('type', $query)) {
            $type = is_string($query['type']) ? JobType::tryFrom($query['type']) : null;
            if ($type === null) {
                $errors['type'] = 'Art: „http“ oder „shell“.';
            }
        }
        $category = null;
        if (array_key_exists('category_id', $query)) {
            $category = self::positiveInt($query['category_id']);
            if ($category === null) {
                $errors['category_id'] = 'Kategorie: positive ganze Zahl.';
            }
        }
        $sort = JobSort::Name;
        if (array_key_exists('sort', $query)) {
            $sortValue = is_string($query['sort']) ? JobSort::tryFrom($query['sort']) : null;
            if ($sortValue === null) {
                $errors['sort'] = 'Sortierung: „name“ oder „next_run“.';
            } else {
                $sort = $sortValue;
            }
        }
        $enabledOnly = false;
        if (array_key_exists('enabled', $query)) {
            if ($query['enabled'] !== '1') {
                $errors['enabled'] = 'Nur aktive Jobs: enabled=1.';
            }
            $enabledOnly = true;
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        // Typ, Kategorie und „nur aktive“ sind Anfragen innerhalb des Bereichs, nie eine Berechtigung.
        $listing = $this->jobs->list($scope, $type, $category, $enabledOnly, $sort);
        $out = [];
        foreach ($listing->jobs as $job) {
            $out[] = $this->presenter->summary($job, $grants);
        }

        return JsonReply::json(['jobs' => $out, 'truncated' => $listing->truncated]);
    }

    public function show(Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [, $grants] = $auth;
        $job = $this->jobs->findVisible(self::id($request), $this->access->scope($grants, Permission::ViewJobs));
        if ($job === null) {
            return JsonReply::error(404, 'Job nicht gefunden.');
        }
        $this->access->require($grants, Permission::ViewJobs, $job->categoryName);

        return JsonReply::json(['job' => $this->presenter->detail($job, $grants)]);
    }

    public function history(Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [, $grants] = $auth;

        $query = $request->query->all();
        $errors = [];
        $limit = self::DEFAULT_RUN_LIMIT;
        if (array_key_exists('limit', $query)) {
            $parsed = self::positiveInt($query['limit']);
            if ($parsed === null || $parsed > RunRepository::MAX_LIMIT) {
                $errors['limit'] = 'Anzahl: ganze Zahl von 1 bis ' . RunRepository::MAX_LIMIT . '.';
            } else {
                $limit = $parsed;
            }
        }
        $before = null;
        if (array_key_exists('before_id', $query)) {
            $before = self::positiveInt($query['before_id']);
            if ($before === null) {
                $errors['before_id'] = 'Cursor: positive ganze Zahl.';
            }
        }
        $status = null;
        if (array_key_exists('status', $query)) {
            $status = is_string($query['status']) ? RunStatus::tryFrom($query['status']) : null;
            if ($status === null) {
                $errors['status'] = 'Status: queued, running, ok, failed, timeout, aborted oder skipped.';
            }
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        $scope = $this->access->scope($grants, Permission::ViewJobs);
        $job = $this->jobs->findVisible(self::id($request), $scope);
        if ($job === null) {
            return JsonReply::error(404, 'Job nicht gefunden.');
        }
        $this->access->require($grants, Permission::ViewJobs, $job->categoryName);

        $records = $this->runs->history($job->id, $scope, $limit, $before, $status);
        $more = count($records) > $limit;
        $page = array_slice($records, 0, $limit);
        $out = [];
        foreach ($page as $run) {
            $out[] = $this->presenter->run($run, false);
        }

        return JsonReply::json([
            'runs' => $out,
            'next_before_id' => $more && $page !== [] ? $page[count($page) - 1]->id : null,
        ]);
    }

    public function runDetail(Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [, $grants] = $auth;
        $run = $this->runs->findVisible(self::id($request), $this->access->scope($grants, Permission::ViewJobs));
        if ($run === null) {
            return JsonReply::error(404, 'Lauf nicht gefunden.');
        }
        // Ein Lauf erbt die Kategorie seines Jobs.
        $this->access->require($grants, Permission::ViewJobs, $run->categoryName);

        return JsonReply::json(['run' => $this->presenter->run($run, true)]);
    }

    public function preview(Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [, $grants] = $auth;
        if ($this->access->scope($grants, Permission::ViewJobs)->isEmpty()) {
            throw new AccessDenied(Permission::ViewJobs);
        }

        $query = $request->query->all();
        $errors = [];
        $cron = $query['cron'] ?? null;
        $timezone = $query['timezone'] ?? null;
        if (!is_string($cron) || $cron === '' || strlen($cron) > 100) {
            $errors['cron'] = 'Cron-Ausdruck: Text mit höchstens 100 Zeichen, fünf Felder, z. B. */5 * * * *.';
        }
        if (!is_string($timezone) || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Unbekannte Zeitzone. Erwartet wird ein Bezeichner wie „Europe/Berlin“.';
        }
        $count = self::DEFAULT_PREVIEW;
        if (array_key_exists('count', $query)) {
            $parsed = self::positiveInt($query['count']);
            if ($parsed === null || $parsed > self::MAX_PREVIEW) {
                $errors['count'] = 'Anzahl: ganze Zahl von 1 bis ' . self::MAX_PREVIEW . '.';
            } else {
                $count = $parsed;
            }
        }
        if ($errors !== [] || !is_string($cron) || !is_string($timezone)) {
            throw new ValidationFailed($errors);
        }

        try {
            $runs = CronSchedule::forJob($cron, $timezone)->nextRuns($this->clock->now(), $count);
        } catch (\InvalidArgumentException) {
            throw ValidationFailed::field('cron', 'Ungültiger Cron-Ausdruck. Erwartet werden fünf Felder, z. B. */5 * * * *.');
        } catch (\RuntimeException) {
            throw ValidationFailed::field('cron', 'Der Zeitplan hat keinen weiteren Termin. Cron-Ausdruck prüfen.');
        }

        return JsonReply::json(['runs' => array_map(static fn (\DateTimeImmutable $time): string => $time->format('c'), $runs)]);
    }

    public function limits(Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [, $grants] = $auth;
        if ($this->access->scope($grants, Permission::ViewJobs)->isEmpty()) {
            throw new AccessDenied(Permission::ViewJobs);
        }

        // Nur diese zwei Werte, keine weiteren Einstellungen.
        return JsonReply::json([
            'max_timeout_seconds' => $this->settings->maxTimeoutSeconds(),
            'response_storage' => $this->settings->responseStorage()->value,
        ]);
    }

    public function categoryList(Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [, $grants] = $auth;

        $query = $request->query->all();
        $permission = Permission::ViewJobs;
        if (array_key_exists('permission', $query)) {
            $permission = match ($query['permission']) {
                'jobs.view' => Permission::ViewJobs,
                'jobs.edit_http' => Permission::EditHttpJobs,
                default => throw ValidationFailed::field('permission', 'Recht: „jobs.view“ oder „jobs.edit_http“.'),
            };
        }

        // Das angefragte Recht bestimmt den Bereich; ohne dieses Recht ist die Liste leer, nie „alles“.
        return JsonReply::json(['categories' => $this->categories->visible($this->access->scope($grants, $permission))]);
    }

    /**
     * Sitzung prüfen (401 ohne), bei ändernden Anfragen CSRF-Token und Herkunft (403, vor dem Laden jedes Datensatzes)
     * und die frischen Rechte laden. Rechte werden bei jeder Anfrage neu gelesen.
     *
     * @return array{0: Session, 1: list<RoleGrant>}|JsonResponse
     */
    private function authenticate(Request $request, bool $mutating = false): array|JsonResponse
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if ($mutating && !$this->csrf->check($request, $session->token)) {
            return JsonReply::error(403, 'CSRF-Prüfung fehlgeschlagen.');
        }

        return [$session, $this->users->grantsFor($session->userId)];
    }

    /**
     * Der Anfragekörper als JSON-Objekt (höchstens 160 KiB, Tiefe 5).
     *
     * @return array<mixed>
     *
     * @throws ValidationFailed
     */
    private static function body(Request $request): array
    {
        $data = JsonBody::object($request->getContent(), JobValidator::MAX_BODY_BYTES, 5);
        $message = 'Der Anfragekörper muss ein JSON-Objekt sein (höchstens 160 KiB, höchstens 5 Ebenen tief).';
        if ($data === null) {
            throw ValidationFailed::field('body', $message);
        }
        if ($data !== [] && array_is_list($data)) {
            throw ValidationFailed::field('body', $message);
        }

        return $data;
    }

    private static function id(Request $request): int
    {
        // Das Muster der Route lässt nur [1-9][0-9]{0,17} durch.
        return (int) $request->attributes->getString('id');
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1 ? (int) $value : null;
    }
}
