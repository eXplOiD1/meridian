<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\Clock;
use Meridian\Auth\PasswordChangeRequired;
use Meridian\Job\LiveChunk;
use Meridian\Job\RunRecord;
use Meridian\Job\RunRepository;
use Meridian\Schedule\RunStatus;
use Meridian\Security\AccessControl;
use Meridian\Security\CategoryScope;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;
use Meridian\Security\SecretMasker;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /api/runs/{id}/live` — Live-Log als Server-Sent Events (ADR 0004 E9, §6.1).
 *
 * Vor dem Strom (JSON-Fehler): Sitzung (401) → Herkunft `Sec-Fetch-Site` (403) → `after`/`Last-Event-ID` (422) →
 * `findVisible()` mit dem Bereich von `jobs.view` (404) → `require(jobs.view, gespeicherte Kategorie)` (403) →
 * Belegung `live_streams` (429 mit `Retry-After`).
 *
 * Im Strom: `retry: 2000`, dann alle 500 ms neue Stücke als `id: <seq>` / `event: chunk` / `data: {stream, text}`
 * (Text erneut maskiert, JSON ohne rohe Zeilenumbrüche), Statuswechsel als `event: status`, alle 15 s Rechte neu
 * prüfen (Sitzung, Benutzer aktiv, Bereich, Recht) und `: ping`. Ende mit `event: end` (`finished`, `forbidden`,
 * `gone`, `error`) oder nach 60 s mit `event: reconnect` (der Browser verbindet sich mit `Last-Event-ID` neu).
 *
 * Keine Transaktion und keine Sperre über die Dauer des Stroms: jede Abfrage ist kurz und einzeln. PHP-Sitzungen
 * (`session_start`) gibt es in Meridian nicht, also auch keine Sitzungssperre.
 */
final class RunLiveController
{
    public const TICK_MILLISECONDS = 500;
    public const RECHECK_SECONDS = 15;
    public const MAX_CONNECTION_SECONDS = 60;
    public const RETRY_MILLISECONDS = 2000;
    /** Höchstens so viele Stücke je Abfrage (ADR 0004 §6.1 Schritt 7). */
    public const CHUNKS_PER_TICK = 64;

    public const MESSAGE_TOO_MANY = 'Zu viele Live-Verbindungen. Die Ansicht aktualisiert sich stattdessen alle 2 Sekunden.';

    /** Ein frischer Masker nur mit Mustern (`token=…`, `Bearer …`): die Stücke sind schon mit dem Masker des Laufs maskiert. */
    private readonly SecretMasker $masker;

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly RunRepository $runs,
        private readonly LiveStreamSlots $slots,
        private readonly Clock $clock,
        private readonly SseChannel $channel,
    ) {
        $this->masker = new SecretMasker();
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('runs_live', '/api/runs/{id}/live', $this->live(...), ['id' => '[1-9][0-9]{0,17}']);
    }

    public function live(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        // Herkunft (Schritt 2): EventSource schickt keine eigenen Header, also kein CSRF-Token; ein Browser meldet
        // fremde Seiten mit „cross-site“/„same-site“. Lesen kann eine fremde Seite den Strom ohnehin nicht (kein CORS).
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null && $site !== 'same-origin') {
            return JsonReply::error(403, 'Anfrage von fremder Herkunft abgelehnt. Die Seite neu laden.');
        }
        $after = self::resumeFrom($request);

        $grants = $this->users->grantsFor($session->userId);
        $runId = (int) $request->attributes->getString('id');
        $run = $this->runs->findVisible($runId, $this->access->scope($grants, Permission::ViewJobs), withOutput: false);
        if ($run === null) {
            return JsonReply::error(404, 'Lauf nicht gefunden.');
        }
        // Ein Lauf erbt die Kategorie seines Jobs (aus der Datenbank, nie aus der Anfrage).
        $this->access->require($grants, Permission::ViewJobs, $run->categoryName);

        $slot = $this->slots->acquire($session->userId, $run->id);
        if ($slot === null) {
            $response = JsonReply::error(429, self::MESSAGE_TOO_MANY);
            $response->headers->set('Retry-After', (string) $this->slots->retryAfterSeconds($session->userId));

            return $response;
        }

        $userId = $session->userId;
        $response = new StreamedResponse(
            function () use ($request, $userId, $grants, $run, $after, $slot): void {
                $this->stream($request, $userId, $grants, $run, $after, $slot);
            },
            200,
            [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Accel-Buffering' => 'no',
            ],
        );

        return $response;
    }

    /**
     * @param list<RoleGrant> $grants
     */
    private function stream(#[\SensitiveParameter] Request $request, int $userId, array $grants, RunRecord $run, int $after, string $slot): void
    {
        $released = false;
        $release = function () use ($slot, &$released): void {
            if ($released) {
                return;
            }
            $released = true;
            try {
                $this->slots->release($slot);
            } catch (\Throwable $e) {
                // Die Zeile läuft ab und der Planer räumt sie; der Strom ist ohnehin zu Ende.
                ErrorLog::unexpected($e, 'RunLive release');
            }
        };
        $this->channel->atShutdown($release);
        try {
            $this->channel->open();
            $this->loop($request, $userId, $grants, $run->id, $after);
        } catch (\Throwable $e) {
            // Nie die Meldung, weder im Log noch an den Client (Regel 4).
            ErrorLog::unexpected($e, 'RunLive');
            $this->channel->send(self::event('end', ['reason' => 'error']));
        } finally {
            $release();
        }
    }

    /**
     * @param list<RoleGrant> $grants
     */
    private function loop(#[\SensitiveParameter] Request $request, int $userId, array $grants, int $runId, int $after): void
    {
        $started = $this->clock->now();
        $deadline = $started->modify('+' . self::MAX_CONNECTION_SECONDS . ' seconds');
        $nextCheck = $started->modify('+' . self::RECHECK_SECONDS . ' seconds');
        $lastStatus = null;

        if (!$this->channel->send('retry: ' . self::RETRY_MILLISECONDS . "\n\n")) {
            return;
        }
        while (true) {
            $now = $this->clock->now();
            if ($now >= $deadline) {
                // Kein Thread hängt länger als 60 s an einem Client; der Browser verbindet sich mit Last-Event-ID neu.
                $this->channel->send(self::event('reconnect', ['after' => $after]));

                return;
            }
            if ($now >= $nextCheck) {
                $nextCheck = $now->modify('+' . self::RECHECK_SECONDS . ' seconds');
                $fresh = $this->recheck($request, $userId);
                if ($fresh === null) {
                    $this->channel->send(self::event('end', ['reason' => 'forbidden']));

                    return;
                }
                $grants = $fresh;
                if (!$this->channel->send(": ping\n\n")) {
                    return;
                }
            }

            // Bei jeder Runde: Lauf über den Bereich der (zuletzt geprüften) Rechte und das Recht in seiner
            // gespeicherten Kategorie. Erst danach Stücke lesen (chunksAfter() prüft keinen Bereich).
            $current = $this->runs->findVisible($runId, $this->access->scope($grants, Permission::ViewJobs), withOutput: false);
            if ($current === null) {
                // Außerhalb des Bereichs nach einer Herabstufung → forbidden; wirklich gelöscht → gone.
                $exists = $this->runs->findVisible($runId, CategoryScope::everything(), withOutput: false) !== null;
                $this->channel->send(self::event('end', ['reason' => $exists ? 'forbidden' : 'gone']));

                return;
            }
            if (!$this->access->can($grants, Permission::ViewJobs, $current->categoryName)) {
                $this->channel->send(self::event('end', ['reason' => 'forbidden']));

                return;
            }
            // Status vor den Stücken lesen: Der Worker schreibt das Live-Log vor dem Endstatus, also sind nach
            // einem gelesenen Endstatus alle Stücke da.
            $finished = $current->status !== RunStatus::Queued && $current->status !== RunStatus::Running;
            $chunks = $this->runs->chunksAfter($runId, $after, self::CHUNKS_PER_TICK);
            foreach ($chunks as $chunk) {
                if (!$this->channel->send($this->chunkEvent($chunk))) {
                    return;
                }
                $after = $chunk->seq;
            }
            $state = ['status' => $current->status->value, 'exit_code' => $current->exitCode];
            if ($current->status !== $lastStatus) {
                $lastStatus = $current->status;
                if (!$this->channel->send(self::event('status', $state))) {
                    return;
                }
            }
            $more = count($chunks) >= self::CHUNKS_PER_TICK;
            if ($finished && !$more) {
                $this->channel->send(self::event('end', ['reason' => 'finished'] + $state));

                return;
            }
            if (!$more) {
                $this->channel->pause(self::TICK_MILLISECONDS);
            }
        }
    }

    /**
     * Neuprüfung während des Stroms: Sitzung noch gültig (nicht abgemeldet, nicht abgelaufen, Benutzer aktiv und
     * nicht gelöscht, kein Pflichtwechsel), derselbe Benutzer, frische Rechte. Bereich und Recht prüft die Schleife.
     *
     * @return list<RoleGrant>|null null = Strom beenden
     */
    private function recheck(#[\SensitiveParameter] Request $request, int $userId): ?array
    {
        try {
            $session = $this->sessionAuth->authenticate($request);
        } catch (PasswordChangeRequired) {
            return null;
        }
        if ($session === null || $session->userId !== $userId) {
            return null;
        }
        $grants = $this->users->grantsFor($userId);

        return $grants === [] ? null : $grants;
    }

    private function chunkEvent(LiveChunk $chunk): string
    {
        return self::event('chunk', ['stream' => $chunk->stream->value, 'text' => $this->masker->mask($chunk->text)], $chunk->seq);
    }

    /**
     * Ein SSE-Ereignis. Die Daten sind JSON in einer Zeile: json_encode() kodiert CR/LF als `\r`/`\n`, ein Lauftext
     * mit „\n\ndata: …“ kann also kein weiteres Ereignis einschleusen.
     *
     * @param array<string, mixed> $data
     */
    public static function event(string $name, array $data, ?int $id = null): string
    {
        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (strpbrk($json, "\r\n") !== false) {
            throw new \LogicException('SSE-Daten enthalten einen Zeilenumbruch.');
        }

        return ($id !== null ? 'id: ' . $id . "\n" : '') . 'event: ' . $name . "\ndata: " . $json . "\n\n";
    }

    /**
     * Fortsetzen ab `Last-Event-ID` (Neuverbindung des Browsers) bzw. `?after=` (erste Verbindung): ganze Zahl ab 0.
     *
     * @throws ValidationFailed
     */
    private static function resumeFrom(#[\SensitiveParameter] Request $request): int
    {
        $errors = [];
        $after = 0;
        $query = $request->query->all();
        if (array_key_exists('after', $query)) {
            $parsed = self::sequence($query['after']);
            if ($parsed === null) {
                $errors['after'] = 'Fortsetzen ab: ganze Zahl ab 0 (letzte erhaltene Nummer).';
            } else {
                $after = $parsed;
            }
        }
        $header = $request->headers->get('Last-Event-ID');
        if ($header !== null) {
            $parsed = self::sequence($header);
            if ($parsed === null) {
                $errors['last_event_id'] = 'Last-Event-ID: ganze Zahl ab 0 (Nummer des letzten Ereignisses).';
            } else {
                // Die Neuverbindung ist neuer als die URL, die der Browser wiederverwendet.
                $after = $parsed;
            }
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return $after;
    }

    private static function sequence(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/D', $value) === 1 ? (int) $value : null;
    }
}
