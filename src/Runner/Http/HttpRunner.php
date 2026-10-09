<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Job\HttpJobConfig;
use Meridian\Job\InvalidJobConfig;
use Meridian\Job\StoreResponse;
use Meridian\Runner\Heartbeat;
use Meridian\Runner\JobType;
use Meridian\Runner\Runner;
use Meridian\Runner\RunRequest;
use Meridian\Runner\RunResult;
use Meridian\Security\SecretBox;
use Meridian\Security\SecretMasker;
use Meridian\Settings\ResponseStorage;
use Meridian\Settings\Settings;

/**
 * Führt einen HTTP-Job aus (docs/decisions/0003, §5.3).
 *
 *  1. Job frisch laden (Kategorie aus der Datenbank), Einstellungen frisch lesen: Zeitlimit = min(Job, Maximum),
 *     Antwort speichern nach E11 (`never` gewinnt).
 *  2. Payload entschlüsseln und **sofort** im Masker dieses Laufs registrieren.
 *  3. Je Hop: `TargetGuard::pin()` (Freigaben mit der gespeicherten Kategorie des Jobs) → `HttpTransport::send()`
 *     mit der verbleibenden Gesamtzeit. Weiterleitungen manuell: neu geprüft, kein https → http, Header und Body
 *     nie an einen anderen Ursprung.
 *  4. Bewertung nach den erwarteten Statuscodes.
 *
 * Ausgabe und Notiz sind feste Texte plus Ziel (Schema, Host, Port), Status, Dauer und Größe; nie Pfad, Query,
 * Header-Werte oder curl-Meldungen. Die Antwort nur, wenn Speichern wirksam ist und sie UTF-8-Text ist. Der Worker
 * maskiert, kürzt und speichert. Gibt ein Ergebnis mit `retryable = false` zurück, wenn eine Wiederholung nichts
 * ändert (gesperrtes Ziel, unlesbare Anfrage, TLS, ungültige Weiterleitung).
 */
final class HttpRunner implements Runner
{
    public const NOTE_NOT_HTTP = 'Fehlgeschlagen: Der Job ist nicht mehr vorhanden oder kein HTTP-Job.';
    public const NOTE_BODY_FOR_GET = 'Die gespeicherte Anfrage hat einen Body, die Methode sendet aber keinen (GET/HEAD). Anfrage im Job neu eingeben oder Methode ändern.';
    public const NOTE_ABORTED = 'Abgebrochen: Der Lauf wurde beendet.';
    public const NOTE_CONNECT = 'Verbindung fehlgeschlagen: Das Ziel ist nicht erreichbar oder hat die Verbindung abgelehnt.';
    public const NOTE_TLS = 'TLS-Prüfung fehlgeschlagen: Zertifikat ungültig, abgelaufen oder für einen anderen Namen.';
    public const NOTE_PIN = 'Ziel gesperrt: Die Verbindung ging an eine nicht geprüfte Adresse und wurde abgebrochen.';
    public const NOTE_HEADERS = 'Die Antwort-Header des Ziels sind zu groß (über 32 KiB).';
    public const NOTE_REDIRECT_INVALID = 'Ungültige Weiterleitung: Das Ziel ist keine zulässige http(s)-Adresse (z. B. Zugangsdaten, fremdes Schema oder unzulässige Zeichen).';
    public const NOTE_REDIRECT_DOWNGRADE = 'Ungültige Weiterleitung: Von https auf http wird nicht weitergeleitet.';
    public const NOTE_REDIRECT_BODY = 'Ungültige Weiterleitung: Eine Anfrage mit Body wird nicht an einen anderen Server weitergeleitet (307/308).';
    public const NOTE_BODY_LIMIT = 'Antwort nach 10 MiB abgebrochen.';

    private const REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly HttpJobSource $jobs,
        private readonly SecretBox $box,
        private readonly HttpRunSettings $settings,
        private readonly TargetGuard $guard,
        private readonly HttpTransport $transport,
        private readonly UrlPolicy $urls = new UrlPolicy(),
    ) {
    }

    #[\Override]
    public function run(RunRequest $request, Heartbeat $heartbeat, SecretMasker $masker): RunResult
    {
        $job = $this->jobs->load($request->jobId);
        if ($job === null || $job->type !== JobType::Http->value) {
            return RunResult::failed('', self::NOTE_NOT_HTTP, retryable: false);
        }
        try {
            // Dieselbe strenge Prüfung wie beim Speichern: fehlt `v` oder ein Feld, keine stillen Standardwerte.
            $spec = HttpJobConfig::fromJson($job->configJson);
        } catch (InvalidJobConfig $e) {
            return RunResult::failed('', $e->getMessage(), retryable: false);
        }

        // Einstellungen bei jedem Lauf frisch (E9/E11).
        $notes = [];
        $maximum = max(Settings::MIN_TIMEOUT_SECONDS, min(Settings::MAX_TIMEOUT_SECONDS, $this->settings->maxTimeoutSeconds()));
        $timeout = $spec->timeoutSeconds;
        if ($timeout > $maximum) {
            $timeout = $maximum;
            $notes[] = 'Zeitlimit auf das Maximum von ' . $maximum . ' s begrenzt.';
        }
        $store = self::storesResponse($spec->storeResponse, $this->settings->responseStorage());

        if (!$heartbeat->beat()) {
            return RunResult::failed('', self::NOTE_ABORTED);
        }
        try {
            $payload = HttpPayload::fromJson($this->box->decrypt($job->payloadEnc));
        } catch (InvalidPayload $e) {
            return RunResult::failed('', $e->getMessage(), retryable: false);
        } catch (\Throwable) {
            // Schlüssel falsch, Wert beschädigt: feste Meldung, nie die der Ausnahme.
            return RunResult::failed('', InvalidPayload::unreadable()->getMessage(), retryable: false);
        }
        // Sofort, vor jeder Ausgabe (§5.3 Schritt 3).
        $payload->registerIn($masker);

        $body = $payload->body();
        if ($body !== null && !$spec->method->allowsBody()) {
            return RunResult::failed('', self::NOTE_BODY_FOR_GET, retryable: false);
        }

        // http.display_host = hidden: auch die Laufausgabe nennt keinen Host (frisch je Lauf gelesen).
        $hideHost = $this->settings->hidesHost();

        return $this->hops($spec, $payload, $job->categoryId, $timeout, $store, $notes, $heartbeat, $masker, $hideHost);
    }

    /**
     * Wirksame Speicherung (E11): `never` gewinnt immer; sonst der Job-Wert, bei `inherit` der globale.
     */
    public static function storesResponse(StoreResponse $job, ResponseStorage $global): bool
    {
        if ($global === ResponseStorage::Never) {
            return false;
        }

        return match ($job) {
            StoreResponse::On => true,
            StoreResponse::Off => false,
            StoreResponse::Inherit => $global === ResponseStorage::On,
        };
    }

    /**
     * @param list<string> $notes
     */
    private function hops(HttpJobConfig $spec, #[\SensitiveParameter] HttpPayload $payload, ?int $categoryId, int $timeout, bool $store, array $notes, Heartbeat $heartbeat, SecretMasker $masker, bool $hideHost): RunResult
    {
        $deadline = (int) hrtime(true) + $timeout * 1_000_000_000;
        $url = $payload->url();
        $origin = $url->origin();
        $method = $spec->method->value;
        $headers = $payload->headers();
        $body = $payload->body();
        $lines = [];

        for ($hop = 0; ; ++$hop) {
            $remainingMs = intdiv($deadline - (int) hrtime(true), 1_000_000);
            if ($remainingMs <= 0) {
                return RunResult::timeout(self::text($lines), self::note([self::timeoutNote($timeout), ...$notes]));
            }
            if (!$heartbeat->beat()) {
                return RunResult::failed('', self::NOTE_ABORTED);
            }
            try {
                // DNS blockiert ohne eigenes Zeitlimit: Herzschlag direkt davor und danach.
                $pinned = $this->guard->pin($url, $categoryId);
            } catch (TargetBlocked $e) {
                return RunResult::failed(self::text($lines), self::note([$e->getMessage(), ...$notes]), retryable: false);
            } catch (TargetUnresolvable $e) {
                return RunResult::failed(self::text($lines), self::note([$e->getMessage(), ...$notes]));
            }
            if (!$heartbeat->beat()) {
                return RunResult::failed('', self::NOTE_ABORTED);
            }

            $lines[] = '→ ' . $method . ' ' . ($hideHost ? UrlDisplay::hiddenOrigin($url->origin()) : $url->origin());
            $response = $this->transport->send(new TransportRequest($method, $pinned, $headers, $body, $remainingMs), $heartbeat);

            if ($response->error !== null) {
                return self::transportFailure($response, $lines, $notes, $timeout);
            }

            $location = $response->location();
            if (in_array($response->status, self::REDIRECTS, true) && $location !== null && $hop < $spec->maxRedirects) {
                $next = $this->redirectTarget($url, $location);
                if ($next === null) {
                    return RunResult::failed(self::text($lines), self::note([self::NOTE_REDIRECT_INVALID, ...$notes]), null, $response->status, false);
                }
                if ($url->scheme === 'https' && $next->scheme === 'http') {
                    return RunResult::failed(self::text($lines), self::note([self::NOTE_REDIRECT_DOWNGRADE, ...$notes]), null, $response->status, false);
                }
                // 303 immer, 301/302 bei POST: weiter mit GET ohne Body (HEAD bleibt HEAD).
                if ($response->status === 303 || (($response->status === 301 || $response->status === 302) && $method === 'POST')) {
                    $method = $method === 'HEAD' ? 'HEAD' : 'GET';
                    $body = null;
                }
                if ($next->origin() !== $origin) {
                    // Anderer Ursprung: nie die Header oder den Body des Jobs mitschicken.
                    if ($body !== null) {
                        return RunResult::failed(self::text($lines), self::note([self::NOTE_REDIRECT_BODY, ...$notes]), null, $response->status, false);
                    }
                    $headers = [];
                }
                $lines[] = sprintf(
                    '← %d · %s · Weiterleitung %d von %d (%s)',
                    $response->status,
                    self::seconds($response->durationMs),
                    $hop + 1,
                    $spec->maxRedirects,
                    $next->origin() === $url->origin() ? 'gleicher Server' : 'anderer Server',
                );
                $url = $next;

                continue;
            }

            return $this->evaluate($spec, $response, $method, $lines, $notes, $store, $masker);
        }
    }

    /**
     * @param list<string> $lines
     * @param list<string> $notes
     */
    private function evaluate(HttpJobConfig $spec, #[\SensitiveParameter] TransportResponse $response, string $method, #[\SensitiveParameter] array $lines, array $notes, bool $store, SecretMasker $masker): RunResult
    {
        $lines[] = sprintf(
            '← %d · %s · %s',
            $response->status,
            self::seconds($response->durationMs),
            $response->bodyLimitReached ? 'über 10 MiB' : self::bytes($response->bodyBytes),
        );
        if ($response->bodyLimitReached) {
            $notes[] = self::NOTE_BODY_LIMIT;
        }
        if ($store && $method !== 'HEAD') {
            $lines[] = self::responseText($response, $masker);
        }

        if (self::expects($spec, $response->status)) {
            return RunResult::ok(self::text($lines), null, $response->status, $notes === [] ? null : self::note($notes));
        }

        $note = 'Unerwarteter Statuscode ' . $response->status . ' (erwartet: ' . $spec->expectedStatusText() . ').';
        $isRedirect = in_array($response->status, self::REDIRECTS, true) && $response->location() !== null;
        if ($isRedirect) {
            // Weiterleitungen aufgebraucht: eine Wiederholung ändert nichts.
            $note .= ' Weiterleitungen: ' . $spec->maxRedirects . ' erlaubt.';
        }

        return RunResult::failed(self::text($lines), self::note([$note, ...$notes]), null, $response->status, !$isRedirect);
    }

    private static function expects(HttpJobConfig $spec, int $status): bool
    {
        foreach ($spec->expectedStatus as [$from, $to]) {
            if ($status >= $from && $status <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $lines
     * @param list<string> $notes
     */
    private static function transportFailure(#[\SensitiveParameter] TransportResponse $response, #[\SensitiveParameter] array $lines, array $notes, int $timeout): RunResult
    {
        $status = $response->status > 0 ? $response->status : null;

        return match ($response->error) {
            TransportError::Aborted => RunResult::failed('', self::NOTE_ABORTED),
            TransportError::Timeout => RunResult::timeout(self::text($lines), self::note([self::timeoutNote($timeout), ...$notes]), $status),
            TransportError::Connect => RunResult::failed(self::text($lines), self::note([self::NOTE_CONNECT, ...$notes]), null, $status),
            TransportError::Tls => RunResult::failed(self::text($lines), self::note([self::NOTE_TLS, ...$notes]), null, $status, false),
            TransportError::PinViolation => RunResult::failed(self::text($lines), self::note([self::NOTE_PIN, ...$notes]), null, $status, false),
            TransportError::HeadersTooLarge => RunResult::failed(self::text($lines), self::note([self::NOTE_HEADERS, ...$notes]), null, $status),
            TransportError::Other, null => RunResult::failed(
                self::text($lines),
                self::note(['Übertragung fehlgeschlagen (curl-Fehler ' . ($response->errorCode ?? 0) . ').', ...$notes]),
                null,
                $status,
            ),
        };
    }

    /**
     * Ziel einer Weiterleitung als geprüfte URL; null, wenn es keine zulässige http(s)-Adresse ist. Ein Fragment
     * wird nie gesendet und fällt weg.
     */
    private function redirectTarget(#[\SensitiveParameter] ParsedUrl $base, #[\SensitiveParameter] string $location): ?ParsedUrl
    {
        $hash = strpos($location, '#');
        if ($hash !== false) {
            $location = substr($location, 0, $hash);
        }
        if ($location === '') {
            return null;
        }

        if (preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*:~', $location) === 1) {
            $absolute = $location;
        } elseif (str_starts_with($location, '//')) {
            $absolute = $base->scheme . ':' . $location;
        } elseif (str_starts_with($location, '/')) {
            $absolute = $base->origin() . $location;
        } elseif (str_starts_with($location, '?')) {
            $absolute = $base->origin() . ($base->path() === '' ? '/' : $base->path()) . $location;
        } else {
            $path = $base->path();
            $slash = strrpos($path, '/');
            $absolute = $base->origin() . ($slash === false ? '/' : substr($path, 0, $slash + 1)) . $location;
        }

        try {
            return $this->urls->parse($absolute);
        } catch (InvalidUrl) {
            return null;
        }
    }

    /**
     * Antwort für die Ausgabe: nur UTF-8-Text (ohne NUL), sonst ein Vermerk mit der Größe. Der Worker maskiert,
     * dann kürzt er. Hat der Transport den Body schon beim Lesen gekürzt (vor dem Maskieren), kann an der Kante ein
     * angeschnittenes Geheimnis stehen: dann hier sofort {@see SecretMasker::maskCut()} (S11-Review).
     */
    private static function responseText(#[\SensitiveParameter] TransportResponse $response, SecretMasker $masker): string
    {
        if ($response->bodyBytes === 0) {
            return 'Antwort: (leer)';
        }
        $body = $response->body();
        $cut = strlen($body) < $response->bodyBytes || $response->bodyLimitReached;
        if ($cut) {
            // Ein am Ende angeschnittenes Mehrbyte-Zeichen entfernen, bevor geprüft wird.
            $body = (string) preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $body);
        }
        if (str_contains($body, "\0") || preg_match('//u', $body) !== 1) {
            return '[Binärinhalt, ' . self::bytes($response->bodyBytes) . ', nicht gespeichert]';
        }
        if ($cut) {
            $body = $masker->maskCut($body);
        }

        return ($cut ? "Antwort (maskiert, gekürzt):\n" : "Antwort (maskiert):\n") . $body;
    }

    private static function timeoutNote(int $timeout): string
    {
        return 'Zeitlimit von ' . $timeout . ' s überschritten.';
    }

    /**
     * @param list<string> $lines
     */
    private static function text(#[\SensitiveParameter] array $lines): string
    {
        return implode("\n", $lines);
    }

    /**
     * @param list<string> $notes
     */
    private static function note(array $notes): string
    {
        return implode(' ', $notes);
    }

    private static function seconds(int $ms): string
    {
        return number_format($ms / 1000, 2, ',', '') . ' s';
    }

    private static function bytes(int $bytes): string
    {
        return number_format($bytes, 0, ',', ' ') . ' B';
    }
}
