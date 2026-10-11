<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\CsrfGuard;
use Meridian\Monitor\MonitorRecord;
use Meridian\Monitor\MonitorService;
use Meridian\Monitor\MonitorRepository;
use Meridian\Security\AccessControl;
use Meridian\Security\AccessDenied;
use Meridian\Security\Permission;
use Meridian\Security\SecretMasker;
use Meridian\Settings\Settings;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Überwachungen (Herzschlag ADR 0011, aktive Prüfungen ADR 0018): `GET/POST /api/monitors`, `PUT/DELETE /api/monitors/{id}`, `POST /api/monitors/{id}/token`.
 *
 * Lesen mit `jobs.view` (auf die erlaubten Kategorien gekappt, leerer Bereich → 403); Anlegen, Ändern, Löschen und Token
 * erneuern mit `jobs.edit_http` in der Kategorie. Nicht sichtbar → 404. Das Ping-Token kommt genau einmal in der Antwort auf
 * Anlegen/Erneuern (`ping_token`), nie in Listen; Namen maskiert, `no-store`.
 */
final class MonitorController
{
    private const MAX_BODY = 4096;

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly MonitorRepository $monitors,
        private readonly MonitorService $service,
        private readonly SecretMasker $masker,
        private readonly Settings $settings,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $id = ['id' => '[1-9][0-9]{0,17}'];
        $kernel->get('monitors_list', '/api/monitors', $this->list(...));
        $kernel->post('monitors_create', '/api/monitors', $this->create(...));
        $kernel->put('monitors_update', '/api/monitors/{id}', $this->update(...), $id);
        $kernel->delete('monitors_delete', '/api/monitors/{id}', $this->delete(...), $id);
        $kernel->post('monitors_token', '/api/monitors/{id}/token', $this->renewToken(...), $id);
    }

    public function list(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request, false);
        if ($auth instanceof Response) {
            return $auth;
        }
        [, $grants] = $auth;
        $scope = $this->access->scope($grants, Permission::ViewJobs);
        if ($scope->isEmpty()) {
            throw new AccessDenied(Permission::ViewJobs);
        }
        $can = $this->access->scope($grants, Permission::EditHttpJobs);

        $hideHost = $this->settings->httpDisplay()->hidesHost();

        return JsonReply::json([
            'monitors' => array_map(fn (MonitorRecord $m): array => $this->present($m, $can->contains($m->categoryName), $hideHost), $this->monitors->list($scope)),
            'min_interval_seconds' => \Meridian\Monitor\MonitorValidator::MIN_INTERVAL,
        ]);
    }

    public function create(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request, true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;
        $created = $this->service->create($session->userId, $grants, self::body($request));

        $body = ['monitor' => $this->present($created['record'], true, $this->settings->httpDisplay()->hidesHost())];
        if ($created['record']->kind === \Meridian\Monitor\MonitorKind::Heartbeat) {
            $body['ping_token'] = $created['plain'];
            $body['note'] = self::NOTE;
        }

        return JsonReply::json($body, 201);
    }

    public function update(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request, true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;
        $record = $this->service->update($session->userId, $grants, self::id($request), self::body($request));

        return $record === null ? JsonReply::error(404, 'Überwachung nicht gefunden.') : JsonReply::json(['monitor' => $this->present($record, true, $this->settings->httpDisplay()->hidesHost())]);
    }

    public function delete(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request, true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;

        return $this->service->delete($session->userId, $grants, self::id($request)) ? JsonReply::noContent() : JsonReply::error(404, 'Überwachung nicht gefunden.');
    }

    public function renewToken(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request, true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;
        $plain = $this->service->regenerateToken($session->userId, $grants, self::id($request));

        return $plain === null ? JsonReply::error(404, 'Überwachung nicht gefunden.') : JsonReply::json(['ping_token' => $plain, 'note' => self::NOTE]);
    }

    private const NOTE = 'Das Ping-Token wird nur jetzt angezeigt. Rufe /ping/<Token> regelmäßig auf (GET oder POST); es lässt sich nicht wieder anzeigen, nur erneuern.';

    /**
     * @return array<string, mixed>
     */
    private function present(MonitorRecord $m, bool $canEdit, bool $hideHost): array
    {
        return [
            'kind' => $m->kind->value,
            'target' => $this->displayTarget($m, $hideHost),
            'expected_status' => $m->kind === \Meridian\Monitor\MonitorKind::Http ? $m->expectedStatus : null,
            'fail_after' => $m->kind->isActive() ? $m->failAfter : null,
            'consecutive_failures' => $m->kind->isActive() ? $m->consecutiveFailures : null,
            'last_check_at' => $m->lastCheckAt,
            'last_check_ok' => $m->lastCheckOk,
            'last_latency_ms' => $m->lastLatencyMs,
            'last_check_note' => $m->lastCheckNote === null ? null : $this->masker->mask($m->lastCheckNote),
            'id' => $m->id,
            'name' => $this->masker->mask($m->name),
            'category_id' => $m->categoryId,
            'category' => $m->categoryName === null ? null : $this->masker->mask($m->categoryName),
            'interval_seconds' => $m->intervalSeconds,
            'grace_seconds' => $m->graceSeconds,
            'enabled' => $m->isEnabled,
            'state' => $m->isEnabled ? $m->state : 'paused',
            'state_since' => $m->stateSince,
            'last_ping_at' => $m->lastPingAt,
            'notify_on_down' => $m->notifyOnDown,
            'notify_on_up' => $m->notifyOnUp,
            'notify_channels' => $m->notifyChannels,
            'can_edit' => $canEdit,
            'created_at' => $m->createdAt,
        ];
    }

    /**
     * @return array{\Meridian\Auth\Session, list<\Meridian\Security\RoleGrant>}|JsonResponse
     */
    private function authenticate(#[\SensitiveParameter] Request $request, bool $mutating): array|JsonResponse
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if ($mutating && !$this->csrf->check($request, $session->token)) {
            return JsonReply::csrfFailed();
        }

        return [$session, $this->users->grantsFor($session->userId)];
    }

    /**
     * @return array<mixed>
     */
    private static function body(#[\SensitiveParameter] Request $request): array
    {
        $data = JsonBody::object($request->getContent(), self::MAX_BODY, 2);
        if ($data === null) {
            throw ValidationFailed::field('body', 'Der Anfragekörper muss ein JSON-Objekt sein (höchstens 4 KiB).');
        }
        if ($data !== [] && array_is_list($data)) {
            throw ValidationFailed::field('body', 'Der Anfragekörper muss ein JSON-Objekt sein (höchstens 4 KiB).');
        }

        return $data;
    }

    private static function id(#[\SensitiveParameter] Request $request): int
    {
        return (int) $request->attributes->getString('id');
    }

    /**
     * Ziel ohne Pfad und Query (so gespeichert); bei „Host in der URL-Anzeige verbergen“ nur die Art mit festem Platzhalter.
     */
    private function displayTarget(MonitorRecord $m, bool $hideHost): ?string
    {
        if (!$m->kind->isActive() || $m->displayTarget === null) {
            return null;
        }
        if ($hideHost) {
            return $m->kind === \Meridian\Monitor\MonitorKind::Http ? (str_starts_with($m->displayTarget, 'https://') ? 'https://' : 'http://') . SecretMasker::MASK : SecretMasker::MASK;
        }

        return $this->masker->mask($m->displayTarget);
    }
}
