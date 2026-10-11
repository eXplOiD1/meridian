<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Auth\CsrfGuard;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Job\Row;
use Meridian\Monitor\MonitorKind;
use Meridian\Monitor\MonitorRepository;
use Meridian\Monitor\MonitorValidator;
use Meridian\Monitor\Probe\Prober;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\Security\SecretBox;
use Meridian\Security\SecretMasker;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Testprüfung einer aktiven Überwachung (ADR 0018): eine einzelne Prüfung jetzt, ohne den Zustand der Überwachung zu ändern und ohne
 * Meldung.
 *
 *   POST /api/monitors/test        {kind, target, expected_status?, category_id?}   Formular vor dem Speichern
 *   POST /api/monitors/{id}/test   {target?, expected_status?}                       gespeicherte Überwachung (ohne Ziel: das gespeicherte)
 *
 * → {"ok", "latency_ms", "note", "checked_at"}. Dieselbe Prüfung wie im Betrieb (`NetworkProbe`: Zielprüfung, SSRF-Schutz, feste Texte),
 * nur im Web-Prozess und synchron (höchstens 10 s). Recht: `jobs.edit_http` in der Kategorie, CSRF; Rate-Limit 30 je Stunde und Benutzer
 * (gezählt über das Audit `monitor.tested`, das nie das Ziel nennt). Das Ziel steht in keiner Antwort.
 */
final class MonitorTestController
{
    public const AUDIT_ACTION = 'monitor.tested';
    public const MAX_PER_HOUR = 30;
    public const RATE_MESSAGE = 'Zu viele Testprüfungen (höchstens 30 je Stunde). Später erneut versuchen.';
    private const MAX_BODY = 4096;

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly MonitorRepository $monitors,
        private readonly MonitorValidator $validator,
        private readonly Prober $prober,
        private readonly SecretBox $box,
        private readonly AuditLog $audit,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SecretMasker $masker,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->post('monitors_test_new', '/api/monitors/test', $this->testNew(...));
        $kernel->post('monitors_test', '/api/monitors/{id}/test', $this->testSaved(...), ['id' => '[1-9][0-9]{0,17}']);
    }

    public function testNew(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;
        $editScope = $this->access->scope($grants, Permission::EditHttpJobs);
        if ($editScope->isEmpty()) {
            throw new \Meridian\Security\AccessDenied(Permission::EditHttpJobs);
        }
        $input = self::body($request);
        $draft = $this->validator->validate($input + ['name' => 'Test', 'interval_seconds' => MonitorValidator::MIN_INTERVAL], $editScope, null);
        $this->access->require($grants, Permission::EditHttpJobs, $draft->categoryName);
        if ($draft->target === null || !$draft->kind->isActive()) {
            throw ValidationFailed::field('target', 'Ein Herzschlag hat nichts zu testen. Wähle eine Web-Adresse, einen Port oder Ping.');
        }

        return $this->run($session->userId, 'neue Prüfung (' . $draft->kind->value . ')', $draft->kind, $draft->target, $draft->categoryId, $draft->expectedStatus);
    }

    public function testSaved(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;
        $id = (int) $request->attributes->getString('id');
        $existing = $this->monitors->findVisible($id, $this->access->scope($grants, Permission::ViewJobs));
        if ($existing === null) {
            return JsonReply::error(404, 'Überwachung nicht gefunden.');
        }
        $this->access->require($grants, Permission::EditHttpJobs, $existing->categoryName);
        if (!$existing->kind->isActive()) {
            throw ValidationFailed::field('kind', 'Ein Herzschlag hat nichts zu testen: Er wartet auf den Ping.');
        }
        $input = self::body($request);
        $draft = $this->validator->validate(array_intersect_key($input, ['target' => 1, 'expected_status' => 1]), $this->access->scope($grants, Permission::EditHttpJobs), $existing);
        $target = $draft->target;
        if ($target === null) {
            $encrypted = $this->monitors->targetEnc($id);
            try {
                $target = $encrypted === null ? null : $this->box->decrypt($encrypted);
            } catch (\Throwable) {
                // Schlüssel falsch, Wert beschädigt: fester Text, nie der der Ausnahme.
                return JsonReply::error(500, 'Das gespeicherte Ziel ist nicht lesbar. Ziel in der Überwachung neu eingeben.');
            }
            if ($target === null) {
                return JsonReply::error(404, 'Diese Überwachung hat kein Ziel.');
            }
        }

        return $this->run($session->userId, 'monitor:' . $id . ' ' . $existing->name, $existing->kind, $target, $existing->categoryId, $draft->expectedStatus);
    }

    private function run(int $userId, string $auditTarget, MonitorKind $kind, #[\SensitiveParameter] string $target, ?int $categoryId, string $expectedStatus): Response
    {
        $retryAfter = $this->db->immediate(function () use ($userId, $auditTarget): ?int {
            $retry = $this->retryAfter($userId);
            if ($retry === null) {
                $this->audit->record($userId, self::AUDIT_ACTION, $auditTarget);
            }

            return $retry;
        });
        if ($retryAfter !== null) {
            $response = JsonReply::error(429, self::RATE_MESSAGE);
            $response->headers->set('Retry-After', (string) $retryAfter);

            return $response;
        }

        $result = $this->prober->probe($kind, $target, $categoryId, $expectedStatus);

        return JsonReply::json([
            'ok' => $result->ok,
            'latency_ms' => $result->latencyMs,
            'note' => $result->ok ? null : $this->masker->mask($result->note),
            'checked_at' => Timestamp::format($this->clock->now()),
        ]);
    }

    private function retryAfter(int $userId): ?int
    {
        $now = $this->clock->now();
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS n, MIN(CAST(strftime(\'%s\', created_at) AS INTEGER)) AS oldest FROM audit_log
              WHERE user_id = :user AND action = :action AND julianday(created_at) > julianday(:since)',
            ['user' => $userId, 'action' => self::AUDIT_ACTION, 'since' => Timestamp::format($now->modify('-1 hour'))],
        );
        if ($row === null || Row::int($row, 'n') < self::MAX_PER_HOUR) {
            return null;
        }
        $oldest = Row::intOrNull($row, 'oldest');
        $seconds = $oldest === null ? 3600 : $oldest + 3600 - $now->getTimestamp();

        return max(1, min(3600, $seconds));
    }

    /**
     * @return array{\Meridian\Auth\Session, list<\Meridian\Security\RoleGrant>}|Response
     */
    private function authenticate(#[\SensitiveParameter] Request $request): array|Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if (!$this->csrf->check($request, $session->token)) {
            return JsonReply::csrfFailed();
        }

        return [$session, $this->users->grantsFor($session->userId)];
    }

    /**
     * @return array<mixed>
     */
    private static function body(#[\SensitiveParameter] Request $request): array
    {
        if (trim($request->getContent()) === '') {
            return [];
        }
        $data = JsonBody::object($request->getContent(), self::MAX_BODY, 2);
        if ($data === null) {
            throw ValidationFailed::field('body', 'Der Anfragekörper muss ein JSON-Objekt sein (höchstens 4 KiB).');
        }
        if ($data !== [] && array_is_list($data)) {
            throw ValidationFailed::field('body', 'Der Anfragekörper muss ein JSON-Objekt sein (höchstens 4 KiB).');
        }

        return $data;
    }
}
