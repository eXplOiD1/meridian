<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Auth\CsrfGuard;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Job\JobRepository;
use Meridian\Job\JobSourceReader;
use Meridian\Job\JobSourceUnreadable;
use Meridian\Job\Row;
use Meridian\Runner\JobType;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\Settings\Settings;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gespeicherte Werte eines Jobs zum Bearbeiten (Entscheidung Alex, ADR 0003 N1 / 0004 N1):
 *
 *   GET /api/jobs/{id}/source → HTTP:  {"job_id", "type": "http",  "url", "headers": [{name, value}], "body"}
 *                               Shell: {"job_id", "type": "shell", "script", "env": [{name, value}]}
 *
 * Bewusste Ausnahme von „keine Geheimnisse in Antworten“: die Werte gehen **unmaskiert** zurück, weil der Editor
 * sie bearbeitbar zeigt. Deshalb streng: nur bei Einstellung `jobs.reveal_for_edit = on`, nie im normalen Detail
 * oder in der Liste, nur mit Bearbeitungsrecht (nicht bloßer Ansicht), `no-store`, Rate-Limit und Audit je Abruf.
 *
 * Ablauf: Sitzung (401; Pflicht-Passwortwechsel → 403) → Herkunft (403): `Sec-Fetch-Site` wenn vorhanden `same-origin`, sonst
 * (reines HTTP) gültiges `X-CSRF-Token` und passender Origin/Referer-Host → Einstellung (403 `reveal_disabled`) → Job über die Sicht `jobs.view` (nicht sichtbar → 404)
 * → `jobs.edit_http` bzw. `jobs.edit_shell` für die gespeicherte Kategorie (403) → in einer Transaktion
 * Rate-Limit (429 + Retry-After) und Audit `job.source_viewed` (nur `job:ID Name`, nie Inhalt) → entschlüsseln.
 * Der Inhalt erscheint in keinem Log, keiner Exception und keiner Fehlermeldung.
 */
final class JobSourceController
{
    public const MAX_PER_HOUR = 60;
    public const AUDIT_ACTION = 'job.source_viewed';
    public const DISABLED_MESSAGE = 'Die Anzeige gespeicherter Skripte und Links ist deaktiviert (Einstellungen).';
    public const ORIGIN_MESSAGE = 'Nur aus der Oberfläche im Browser abrufbar (gleiche Herkunft, gültiges Sitzungs-Token). Die Seite neu laden.';
    public const RATE_MESSAGE = 'Zu viele Abrufe gespeicherter Werte (höchstens 60 je Stunde). Später erneut versuchen.';

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly JobRepository $jobs,
        private readonly Settings $settings,
        private readonly JobSourceReader $reader,
        private readonly AuditLog $audit,
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('jobs_source', '/api/jobs/{id}/source', $this->show(...), ['id' => '[1-9][0-9]{0,17}']);
    }

    public function show(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        // Browser senden Sec-Fetch-Site nur bei „vertrauenswürdigen“ Herkünften (HTTPS, localhost), nicht bei
        // http://<LAN-IP>. Fehlt der Header, zählt stattdessen das CSRF-Token im Header (ohne CORS-Preflight setzt
        // es keine fremde Seite) und, falls vorhanden, ein zum Host passender Origin/Referer.
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null) {
            if ($site !== 'same-origin') {
                return JsonReply::error(403, self::ORIGIN_MESSAGE);
            }
        } else {
            if (!$this->csrf->tokenValid($request, $session->token)) {
                return JsonReply::csrfFailed();
            }
            if (!$this->csrf->originMatchesIfPresent($request)) {
                return JsonReply::error(403, self::ORIGIN_MESSAGE);
            }
        }
        if (!$this->settings->revealForEdit()) {
            return JsonReply::json(['error' => self::DISABLED_MESSAGE, 'reveal_disabled' => true], 403);
        }

        $grants = $this->users->grantsFor($session->userId);
        $job = $this->jobs->findVisible((int) $request->attributes->getString('id'), $this->access->scope($grants, Permission::ViewJobs));
        if ($job === null) {
            return JsonReply::error(404, 'Job nicht gefunden.');
        }
        // Bearbeitungsrecht für die gespeicherte Kategorie, nie bloße Ansicht (wirft AccessDenied → 403).
        $this->access->require($grants, $job->type === JobType::Shell ? Permission::EditShellJobs : Permission::EditHttpJobs, $job->categoryName);

        $retryAfter = $this->db->immediate(function () use ($session, $job): ?int {
            $retry = $this->retryAfter($session->userId);
            if ($retry === null) {
                $this->audit->record($session->userId, self::AUDIT_ACTION, 'job:' . $job->id . ' ' . $job->name);
            }

            return $retry;
        });
        if ($retryAfter !== null) {
            $response = JsonReply::error(429, self::RATE_MESSAGE);
            $response->headers->set('Retry-After', (string) $retryAfter);

            return $response;
        }

        try {
            $source = $this->reader->read($job->id, $job->type);
        } catch (JobSourceUnreadable $e) {
            ErrorLog::unexpected($e, 'JobSource');

            return JsonReply::error(500, JobSourceUnreadable::MESSAGE);
        }
        if ($source === null) {
            return JsonReply::error(404, 'Für diesen Job ist keine Anfrage bzw. kein Skript gespeichert.');
        }

        return self::reply(['job_id' => $job->id, 'type' => $job->type->value] + $source->open());
    }

    /**
     * Sekunden bis zum nächsten erlaubten Abruf, oder null, solange das Limit nicht erreicht ist.
     */
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
        // Ältester Abruf im Fenster (Unix-Sekunden); frei wird es, sobald er eine Stunde alt ist.
        $oldest = Row::intOrNull($row, 'oldest');
        $seconds = $oldest === null ? 3600 : $oldest + 3600 - $now->getTimestamp();

        return max(1, min(3600, $seconds));
    }

    /**
     * Wie {@see JsonReply::json()} (`no-store`, ungültiges UTF-8 ersetzt), dazu `Pragma: no-cache` für alte Zwischenspeicher.
     *
     * @param array<string, mixed> $data
     */
    private static function reply(#[\SensitiveParameter] array $data): JsonResponse
    {
        $response = JsonReply::json($data);
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
