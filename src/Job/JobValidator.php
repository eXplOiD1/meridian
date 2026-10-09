<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Auth\Clock;
use Meridian\Http\ValidationFailed;
use Meridian\Runner\Http\AddressPolicy;
use Meridian\Runner\Http\HttpPayload;
use Meridian\Runner\Http\InvalidPayload;
use Meridian\Runner\Http\InvalidUrl;
use Meridian\Runner\Http\UrlDisplay;
use Meridian\Runner\Http\UrlPolicy;
use Meridian\Runner\JobType;
use Meridian\Schedule\CronSchedule;
use Meridian\Schedule\OverlapPolicy;
use Meridian\Schedule\RetryPolicy;
use Meridian\Security\CategoryScope;
use Meridian\Settings\Settings;

/**
 * Prüft die Eingabe für einen HTTP-Job nach docs/decisions/0003, §3.2: Allowlist, ablehnen statt
 * zurechtschneiden. Die Meldungen sind feste Texte und nennen nie den Eingabewert (er kann ein Geheimnis sein).
 *
 * Beim Ändern (`$existing` gesetzt) gelten für fehlende Felder die gespeicherten Werte. `request` ersetzt URL,
 * Header und Body immer **zusammen**; fehlt es, bleibt die gespeicherte Anfrage unverändert (E3) und der
 * Validator liest oder entschlüsselt sie nie.
 */
final class JobValidator
{
    /** Obergrenze des ganzen Anfragekörpers (JSON) der Job-API. */
    public const MAX_BODY_BYTES = 163840;
    public const DEFAULT_TIMEOUT_SECONDS = 30;
    public const DEFAULT_RETRY_DELAY_SECONDS = 60;
    public const DEFAULT_REDIRECTS = 3;

    private const TOP_KEYS = ['name', 'type', 'category_id', 'cron', 'timezone', 'is_enabled', 'catch_up', 'overlap_policy', 'retry_count', 'retry_delay_seconds', 'http', 'request'];
    private const HTTP_KEYS = ['method', 'timeout_seconds', 'expected_status', 'max_redirects', 'store_response'];
    private const REQUEST_KEYS = ['url', 'headers', 'body'];

    private const MSG_REQUIRED = 'Pflichtfeld: bitte einen Wert angeben.';
    private const MSG_CATEGORY = 'Kategorie unbekannt oder nicht erlaubt.';

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly ?AddressPolicy $addresses = null,
        private readonly string $defaultTimezone = 'Europe/Berlin',
    ) {
    }

    /**
     * @param array<mixed> $input      der Anfragekörper (bereits als Objekt gelesen)
     * @param CategoryScope $editScope Bereich des Rechts `jobs.edit_http` des Benutzers
     *
     * @throws ValidationFailed
     */
    public function validate(#[\SensitiveParameter] array $input, CategoryScope $editScope, ?JobRecord $existing): JobDraft
    {
        /** @var array<string, string> $errors */
        $errors = [];

        $unknown = array_diff(array_map(strval(...), array_keys($input)), self::TOP_KEYS);
        if ($unknown !== []) {
            throw ValidationFailed::field('body', 'Unbekannte Felder im Anfragekörper. Erlaubt sind: ' . implode(', ', self::TOP_KEYS) . '.');
        }

        $this->checkType($input, $existing);

        $http = self::objectField($input, 'http');
        if ($http === null) {
            throw ValidationFailed::field('http', 'Muss ein Objekt sein.');
        }
        $unknownHttp = array_diff(array_map(strval(...), array_keys($http)), self::HTTP_KEYS);
        if ($unknownHttp !== []) {
            throw ValidationFailed::field('http', 'Unbekannte Felder in „http“. Erlaubt sind: ' . implode(', ', self::HTTP_KEYS) . '.');
        }

        $name = $this->name($input, $existing, $errors);
        [$categoryId, $categoryName] = $this->category($input, $existing, $editScope, $errors);
        $timezone = $this->timezone($input, $existing, $errors);
        $cron = $this->cron($input, $existing, $timezone, $errors);
        $isEnabled = $this->bool($input, 'is_enabled', $existing === null ? true : $existing->isEnabled, $errors);
        $catchUp = $this->bool($input, 'catch_up', $existing === null ? false : $existing->catchUp, $errors);
        $overlap = $this->overlap($input, $existing, $errors);
        $retryCount = $this->integer($input, 'retry_count', $existing === null ? 0 : $existing->retryCount, 0, RetryPolicy::MAX_RETRIES, 'Wiederholungen: ganze Zahl von 0 bis ' . RetryPolicy::MAX_RETRIES . '.', $errors);
        $retryDelay = $this->integer($input, 'retry_delay_seconds', $existing === null ? self::DEFAULT_RETRY_DELAY_SECONDS : $existing->retryDelaySeconds, 1, RetryPolicy::MAX_DELAY_SECONDS, 'Abstand der Wiederholungen: ganze Zahl von 1 bis 3600 (Sekunden).', $errors);

        $old = $existing?->http;
        $method = $this->method($http, $old, $errors);
        $maxTimeout = $this->settings->maxTimeoutSeconds();
        $timeout = $this->integer($http, 'timeout_seconds', $old === null ? min(self::DEFAULT_TIMEOUT_SECONDS, $maxTimeout) : $old->timeoutSeconds, 1, $maxTimeout, 'Zeitlimit höchstens ' . $maxTimeout . ' s (Einstellung des Administrators), mindestens 1 s.', $errors, 'http.timeout_seconds');
        $expected = $this->expectedStatus($http, $old, $errors);
        $redirects = $this->integer($http, 'max_redirects', $old === null ? self::DEFAULT_REDIRECTS : $old->maxRedirects, 0, 5, 'Weiterleitungen: ganze Zahl von 0 bis 5.', $errors, 'http.max_redirects');
        $store = $this->storeResponse($http, $old, $errors);

        $payload = $this->request($input, $existing, $method, $categoryId, $errors);

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        if ($name === null || $categoryName === false || $timezone === null || $cron === null || $isEnabled === null || $catchUp === null
            || $overlap === null || $retryCount === null || $retryDelay === null || $method === null || $timeout === null
            || $expected === null || $redirects === null || $store === null) {
            throw new \LogicException('Validierung ohne Fehlermeldung abgebrochen.');
        }

        $config = $payload !== null
            ? $this->configFor($payload, $method, $timeout, $expected, $redirects, $store)
            : $this->configKeepingRequest($old, $method, $timeout, $expected, $redirects, $store);

        return new JobDraft(
            $name,
            JobType::Http,
            $categoryId,
            $categoryName,
            $cron,
            $timezone,
            $isEnabled,
            $catchUp,
            $overlap,
            $retryCount,
            $retryDelay,
            $config,
            $payload,
        );
    }

    /**
     * @param array<mixed> $input
     *
     * @throws ValidationFailed
     */
    private function checkType(#[\SensitiveParameter] array $input, ?JobRecord $existing): void
    {
        $type = array_key_exists('type', $input) ? $input['type'] : $existing?->type->value;
        if ($type === null) {
            throw ValidationFailed::field('type', self::MSG_REQUIRED);
        }
        if ($type === 'shell') {
            throw ValidationFailed::field('type', 'Shell-Jobs folgen mit Phase 4.');
        }
        if ($type !== 'http') {
            throw ValidationFailed::field('type', 'Erlaubt ist „http“.');
        }
        if ($existing !== null && $existing->type !== JobType::Http) {
            throw ValidationFailed::field('type', 'Der Typ eines Jobs lässt sich nicht ändern.');
        }
    }

    /**
     * Ein optionales Objektfeld: fehlt es, ist es leer; ist es kein JSON-Objekt (z. B. Liste oder Text), null.
     *
     * @param array<mixed> $source
     *
     * @return array<mixed>|null
     */
    private static function objectField(#[\SensitiveParameter] array $source, string $key): ?array
    {
        return array_key_exists($key, $source) ? self::asObject($source[$key]) : [];
    }

    /**
     * @return array<mixed>|null
     */
    private static function asObject(#[\SensitiveParameter] mixed $value): ?array
    {
        return is_array($value) && ($value === [] || !array_is_list($value)) ? $value : null;
    }

    /**
     * @param array<mixed>          $input
     * @param array<string, string> $errors
     */
    private function name(#[\SensitiveParameter] array $input, ?JobRecord $existing, array &$errors): ?string
    {
        $name = array_key_exists('name', $input) ? $input['name'] : $existing?->name;
        if ($name === null) {
            $errors['name'] = self::MSG_REQUIRED;

            return null;
        }
        if (!is_string($name) || preg_match('//u', $name) !== 1 || mb_strlen($name) < 1 || mb_strlen($name) > 100
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $name) === 1 || preg_match('/^\s|\s$/u', $name) === 1) {
            $errors['name'] = 'Name: 1 bis 100 Zeichen, ohne Steuerzeichen und ohne Leerraum am Anfang oder Ende.';

            return null;
        }

        return $name;
    }

    /**
     * @param array<mixed>          $input
     * @param array<string, string> $errors
     *
     * @return array{0: int|null, 1: string|null|false} false als Name = ungültig
     */
    private function category(#[\SensitiveParameter] array $input, ?JobRecord $existing, CategoryScope $editScope, array &$errors): array
    {
        $value = array_key_exists('category_id', $input) ? $input['category_id'] : $existing?->categoryId;
        if ($value === null) {
            // „Ohne Kategorie“ nur für uneingeschränkte Rollen.
            if (!$editScope->isAll()) {
                $errors['category_id'] = self::MSG_CATEGORY;

                return [null, false];
            }

            return [null, null];
        }
        $name = is_int($value) ? $this->categories->nameOf($value) : null;
        if (!is_int($value) || $name === null || !$editScope->contains($name)) {
            // Unbekannt und nicht erlaubt sehen gleich aus: die Antwort verrät nicht, welche Kategorien es gibt.
            $errors['category_id'] = self::MSG_CATEGORY;

            return [null, false];
        }

        return [$value, $name];
    }

    /**
     * @param array<mixed>          $input
     * @param array<string, string> $errors
     */
    private function timezone(#[\SensitiveParameter] array $input, ?JobRecord $existing, array &$errors): ?string
    {
        $value = array_key_exists('timezone', $input) ? $input['timezone'] : ($existing->timezone ?? $this->defaultTimezone);
        if (!is_string($value) || !in_array($value, \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Unbekannte Zeitzone. Erwartet wird ein Bezeichner wie „Europe/Berlin“.';

            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed>          $input
     * @param array<string, string> $errors
     */
    private function cron(#[\SensitiveParameter] array $input, ?JobRecord $existing, ?string $timezone, array &$errors): ?string
    {
        $value = array_key_exists('cron', $input) ? $input['cron'] : $existing?->cron;
        if ($value === null) {
            $errors['cron'] = self::MSG_REQUIRED;

            return null;
        }
        if (!is_string($value) || strlen($value) > 100) {
            $errors['cron'] = 'Ungültiger Cron-Ausdruck: Text mit höchstens 100 Zeichen, fünf Felder, z. B. */5 * * * *.';

            return null;
        }
        try {
            $schedule = CronSchedule::forJob($value, $timezone ?? 'UTC');
            $schedule->nextAfter($this->clock->now());

            return $schedule->expression();
        } catch (\InvalidArgumentException) {
            $errors['cron'] = 'Ungültiger Cron-Ausdruck. Erwartet werden fünf Felder, z. B. */5 * * * *.';
        } catch (\RuntimeException) {
            $errors['cron'] = 'Der Zeitplan hat keinen weiteren Termin. Cron-Ausdruck prüfen.';
        }

        return null;
    }

    /**
     * @param array<mixed>          $source
     * @param array<string, string> $errors
     */
    private function bool(#[\SensitiveParameter] array $source, string $key, bool $default, array &$errors): ?bool
    {
        $value = array_key_exists($key, $source) ? $source[$key] : $default;
        if (!is_bool($value)) {
            $errors[$key] = 'Muss true oder false sein.';

            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed>          $input
     * @param array<string, string> $errors
     */
    private function overlap(#[\SensitiveParameter] array $input, ?JobRecord $existing, array &$errors): ?OverlapPolicy
    {
        if (!array_key_exists('overlap_policy', $input)) {
            return $existing === null ? OverlapPolicy::Skip : $existing->overlapPolicy;
        }
        $policy = is_string($input['overlap_policy']) ? OverlapPolicy::tryFrom($input['overlap_policy']) : null;
        if ($policy === null) {
            $errors['overlap_policy'] = 'Überlappung: erlaubt sind „skip“, „parallel“ und „queue“.';
        }

        return $policy;
    }

    /**
     * @param array<mixed>          $source
     * @param array<string, string> $errors
     */
    private function integer(#[\SensitiveParameter] array $source, string $key, int $default, int $min, int $max, string $message, array &$errors, ?string $field = null): ?int
    {
        $value = array_key_exists($key, $source) ? $source[$key] : $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            $errors[$field ?? $key] = $message;

            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed>          $http
     * @param array<string, string> $errors
     */
    private function method(#[\SensitiveParameter] array $http, ?HttpJobConfig $old, array &$errors): ?HttpMethod
    {
        if (!array_key_exists('method', $http)) {
            return $old === null ? HttpMethod::Get : $old->method;
        }
        $method = is_string($http['method']) ? HttpMethod::tryFrom($http['method']) : null;
        if ($method === null) {
            $errors['http.method'] = 'Methode: erlaubt sind GET, POST, PUT, PATCH, DELETE und HEAD (Großbuchstaben).';
        }

        return $method;
    }

    /**
     * @param array<mixed>          $http
     * @param array<string, string> $errors
     *
     * @return list<array{0: int, 1: int}>|null
     */
    private function expectedStatus(#[\SensitiveParameter] array $http, ?HttpJobConfig $old, array &$errors): ?array
    {
        if (!array_key_exists('expected_status', $http)) {
            return $old === null ? [[200, 299]] : $old->expectedStatus;
        }
        $ranges = is_string($http['expected_status']) ? HttpJobConfig::parseExpectedStatus($http['expected_status']) : null;
        if ($ranges === null) {
            $errors['http.expected_status'] = 'Erwartete Statuscodes: Text wie „200-299“ oder „200,204,301-302“ (Codes 100 bis 599, ohne Leerzeichen, höchstens 20 Bereiche, von höchstens bis).';
        }

        return $ranges;
    }

    /**
     * @param array<mixed>          $http
     * @param array<string, string> $errors
     */
    private function storeResponse(#[\SensitiveParameter] array $http, ?HttpJobConfig $old, array &$errors): ?StoreResponse
    {
        if (!array_key_exists('store_response', $http)) {
            return $old === null ? StoreResponse::Inherit : $old->storeResponse;
        }
        $store = is_string($http['store_response']) ? StoreResponse::tryFrom($http['store_response']) : null;
        if ($store === null) {
            $errors['http.store_response'] = 'Antwort speichern: erlaubt sind „inherit“, „on“ und „off“.';
        }

        return $store;
    }

    /**
     * Die Anfrage (URL, Header, Body) als Ganzes. null = bleibt unverändert (nur beim Ändern ohne `request`).
     *
     * @param array<mixed>          $input
     * @param array<string, string> $errors
     */
    private function request(#[\SensitiveParameter] array $input, ?JobRecord $existing, ?HttpMethod $method, ?int $categoryId, array &$errors): ?HttpPayload
    {
        if (!array_key_exists('request', $input)) {
            $stored = $existing?->http;
            if ($existing === null) {
                $errors['request'] = 'Pflichtfeld: die Anfrage (url, optional headers und body) angeben.';
            } elseif ($stored === null) {
                $errors['request'] = 'Die gespeicherte Anfrage ist unlesbar. Bitte URL, Header und Body neu eingeben.';
            } elseif ($method !== null && !$method->allowsBody() && $stored->hasBody) {
                $errors['http.method'] = 'Die gespeicherte Anfrage enthält einen Body; GET und HEAD senden keinen. Die Anfrage ohne Body ersetzen oder eine andere Methode wählen.';
            }

            return null;
        }

        $request = self::objectField($input, 'request');
        if ($request === null) {
            $errors['request'] = 'Muss ein Objekt mit url, headers und body sein.';

            return null;
        }
        $unknown = array_diff(array_map(strval(...), array_keys($request)), self::REQUEST_KEYS);
        if ($unknown !== []) {
            $errors['request'] = 'Unbekannte Felder in „request“. Erlaubt sind: ' . implode(', ', self::REQUEST_KEYS) . '.';

            return null;
        }

        $url = $request['url'] ?? null;
        if (!is_string($url)) {
            $errors['request.url'] = 'Pflichtfeld: die URL als Text angeben.';

            return null;
        }
        $headers = $this->headers($request['headers'] ?? [], $errors);
        $body = $request['body'] ?? null;
        if ($body !== null && !is_string($body)) {
            $errors['request.body'] = 'Der Body muss Text oder null sein.';

            return null;
        }
        if ($headers === null) {
            return null;
        }
        if ($body !== null && $method !== null && !$method->allowsBody()) {
            $errors['request.body'] = 'Bei GET und HEAD wird kein Body gesendet. Body entfernen oder eine andere Methode wählen.';

            return null;
        }

        try {
            $payload = new HttpPayload($url, $headers, $body);
        } catch (InvalidUrl $e) {
            $errors['request.url'] = $e->getMessage();

            return null;
        } catch (InvalidPayload $e) {
            $errors[$e->field] = $e->getMessage();

            return null;
        }

        $this->checkTarget($payload, $categoryId, $errors);

        return $payload;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private function headers(#[\SensitiveParameter] mixed $value, array &$errors): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors['request.headers'] = 'Header: Liste von Objekten mit name und value.';

            return null;
        }
        $headers = [];
        foreach ($value as $i => $header) {
            if (!is_array($header) || array_keys($header) !== ['name', 'value'] && array_keys($header) !== ['value', 'name']
                || !is_string($header['name']) || !is_string($header['value'])) {
                $errors['request.headers[' . $i . ']'] = 'Ein Header ist ein Objekt mit name und value (beide Text).';

                return null;
            }
            $headers[] = [$header['name'], $header['value']];
        }

        return $headers;
    }

    /**
     * Sofortige Rückmeldung beim Speichern: gesperrte Namen und IP-Literale. Aufgelöst wird hier nie (keine
     * DNS-Abfrage aus dem Webprozess); die verbindliche Prüfung macht der Runner bei jedem Lauf.
     *
     * @param array<string, string> $errors
     */
    private function checkTarget(#[\SensitiveParameter] HttpPayload $payload, ?int $categoryId, array &$errors): void
    {
        $url = $payload->url();
        if (!$url->isIpLiteral && UrlPolicy::isBlockedHostname($url->host)) {
            $errors['request.url'] = 'Dieser Host ist als Ziel gesperrt (localhost und Metadaten-Dienste).';

            return;
        }
        if ($url->isIpLiteral && $this->addresses !== null) {
            $reason = $this->addresses->check($url->host, $url->host, $url->port, $categoryId);
            if ($reason !== null) {
                $errors['request.url'] = $reason->isReleasable()
                    ? 'Ziel gesperrt: Die Adresse liegt in einem internen oder reservierten Netz. Ein Admin kann interne Ziele freigeben (global oder für die Kategorie des Jobs).'
                    : 'Ziel gesperrt: Die Adresse liegt in einem Netz, das nie freigegeben werden kann (z. B. Link-local, Metadaten-Dienst, Multicast, reserviert oder Meridians eigene Infrastruktur: Docker-Proxy, Docker-API-Ports 2375/2376, eigener Port).';
            }
        }
    }

    /**
     * @param list<array{0: int, 1: int}> $expected
     */
    private function configFor(#[\SensitiveParameter] HttpPayload $payload, HttpMethod $method, int $timeout, array $expected, int $redirects, StoreResponse $store): HttpJobConfig
    {
        $url = $payload->url();

        return new HttpJobConfig(
            $method,
            $timeout,
            $expected,
            $redirects,
            $store,
            $url->scheme,
            $url->host,
            $url->port,
            // Die einzige Stelle, die die Anzeige-URL bildet: beim Ersetzen der Anfrage, aus der geprüften URL.
            UrlDisplay::build($url, $this->settings->httpDisplay()),
            $payload->headerCount() > 0,
            $payload->headerCount(),
            $payload->hasBody(),
        );
    }

    /**
     * @param list<array{0: int, 1: int}> $expected
     */
    private function configKeepingRequest(?HttpJobConfig $old, HttpMethod $method, int $timeout, array $expected, int $redirects, StoreResponse $store): HttpJobConfig
    {
        if ($old === null) {
            throw new \LogicException('Ohne gespeicherte Konfiguration ist eine neue Anfrage Pflicht.');
        }

        return new HttpJobConfig(
            $method,
            $timeout,
            $expected,
            $redirects,
            $store,
            $old->scheme,
            $old->host,
            $old->port,
            $old->displayUrl,
            $old->hasHeaders,
            $old->headerCount,
            $old->hasBody,
        );
    }
}
