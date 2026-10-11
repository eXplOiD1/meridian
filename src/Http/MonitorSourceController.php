<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Auth\CsrfGuard;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Job\Row;
use Meridian\Monitor\MonitorRepository;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\Security\SecretBox;
use Meridian\Settings\Settings;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gespeichertes Ziel einer aktiven Überwachung zum Bearbeiten (ADR 0018, wie `GET /api/jobs/{id}/source`):
 *
 *   GET /api/monitors/{id}/source → {"monitor_id", "kind", "target"}
 *
 * Bewusste Ausnahme von „keine Geheimnisse in Antworten“ (die Adresse kann Tokens in Pfad oder Query tragen), deshalb gleich streng
 * wie bei Jobs: nur bei Einstellung `jobs.reveal_for_edit = on`, nur aus der Oberfläche (`Sec-Fetch-Site: same-origin` oder gültiges
 * CSRF-Token im Header), nur mit Bearbeitungsrecht (`jobs.edit_http`) für die gespeicherte Kategorie, `no-store`, gemeinsames Rate-Limit
 * mit den Job-Abrufen (60 je Stunde) und Audit `monitor.source_viewed` (nur `monitor:ID Name`, nie das Ziel). Herzschlag-Überwachungen
 * haben kein Ziel (404). API-Tokens sind ausgeschlossen (`SessionAuth`).
 */
final class MonitorSourceController
{
    public const AUDIT_ACTION = 'monitor.source_viewed';

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly MonitorRepository $monitors,
        private readonly Settings $settings,
        private readonly SecretBox $box,
        private readonly AuditLog $audit,
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('monitors_source', '/api/monitors/{id}/source', $this->show(...), ['id' => '[1-9][0-9]{0,17}']);
    }

    public function show(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null) {
            if ($site !== 'same-origin') {
                return JsonReply::error(403, JobSourceController::ORIGIN_MESSAGE);
            }
        } else {
            if (!$this->csrf->tokenValid($request, $session->token)) {
                return JsonReply::csrfFailed();
            }
            if (!$this->csrf->originMatchesIfPresent($request)) {
                return JsonReply::error(403, JobSourceController::ORIGIN_MESSAGE);
            }
        }
        if (!$this->settings->revealForEdit()) {
            return JsonReply::json(['error' => JobSourceController::DISABLED_MESSAGE, 'reveal_disabled' => true], 403);
        }

        $grants = $this->users->grantsFor($session->userId);
        $id = (int) $request->attributes->getString('id');
        $monitor = $this->monitors->findVisible($id, $this->access->scope($grants, Permission::ViewJobs));
        if ($monitor === null) {
            return JsonReply::error(404, 'Überwachung nicht gefunden.');
        }
        $this->access->require($grants, Permission::EditHttpJobs, $monitor->categoryName);
        if (!$monitor->kind->isActive()) {
            return JsonReply::error(404, 'Diese Überwachung hat kein Ziel.');
        }

        $retryAfter = $this->db->immediate(function () use ($session, $monitor): ?int {
            $retry = $this->retryAfter($session->userId);
            if ($retry === null) {
                $this->audit->record($session->userId, self::AUDIT_ACTION, 'monitor:' . $monitor->id . ' ' . $monitor->name);
            }

            return $retry;
        });
        if ($retryAfter !== null) {
            $response = JsonReply::error(429, JobSourceController::RATE_MESSAGE);
            $response->headers->set('Retry-After', (string) $retryAfter);

            return $response;
        }

        $encrypted = $this->monitors->targetEnc($monitor->id);
        try {
            $target = $encrypted === null ? null : $this->box->decrypt($encrypted);
        } catch (\Throwable) {
            // Schlüssel falsch, Wert beschädigt: feste Meldung, nie die der Ausnahme.
            return JsonReply::error(500, 'Das gespeicherte Ziel ist nicht lesbar. Ziel in der Überwachung neu eingeben.');
        }
        if ($target === null) {
            return JsonReply::error(404, 'Diese Überwachung hat kein Ziel.');
        }
        $response = JsonReply::json(['monitor_id' => $monitor->id, 'kind' => $monitor->kind->value, 'target' => $target]);
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Sekunden bis zum nächsten erlaubten Abruf, oder null; zählt Job- und Überwachungs-Abrufe gemeinsam.
     */
    private function retryAfter(int $userId): ?int
    {
        $now = $this->clock->now();
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS n, MIN(CAST(strftime(\'%s\', created_at) AS INTEGER)) AS oldest FROM audit_log
              WHERE user_id = :user AND action IN (:jobs, :monitors) AND julianday(created_at) > julianday(:since)',
            ['user' => $userId, 'jobs' => JobSourceController::AUDIT_ACTION, 'monitors' => self::AUDIT_ACTION, 'since' => Timestamp::format($now->modify('-1 hour'))],
        );
        if ($row === null || Row::int($row, 'n') < JobSourceController::MAX_PER_HOUR) {
            return null;
        }
        $oldest = Row::intOrNull($row, 'oldest');
        $seconds = $oldest === null ? 3600 : $oldest + 3600 - $now->getTimestamp();

        return max(1, min(3600, $seconds));
    }
}
