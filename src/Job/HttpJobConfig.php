<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Runner\Http\UrlDisplay;
use Meridian\Runner\Http\UrlPolicy;

/**
 * Der nicht geheime Teil eines HTTP-Jobs: `jobs.config_json` Version 1 (docs/decisions/0003, E1).
 *
 * Enthält nie Geheimnisse: Methode, Zeitlimit, Statuscodes, Weiterleitungen, Ziel (Schema, Host, Port), die
 * beim Speichern von {@see UrlDisplay} erzeugte Anzeige-URL und Flags/Anzahl für Header und Body. URL, Header
 * und Body selbst stehen nur verschlüsselt in `payload_enc`.
 */
final readonly class HttpJobConfig
{
    public const VERSION = 1;
    public const MAX_STATUS_RANGES = 20;

    private const HTTP_KEYS = ['display_url', 'display_v', 'expected_status', 'has_body', 'has_headers', 'header_count', 'max_redirects', 'method', 'store_response', 'target', 'timeout_seconds'];

    /**
     * @param list<array{0: int, 1: int}> $expectedStatus Bereiche von–bis, z. B. [[200, 299]]
     * @param 'http'|'https'              $scheme
     * @param string                      $host           Kleinbuchstaben; IPv6 kanonisch ohne Klammern
     */
    public function __construct(
        public HttpMethod $method,
        public int $timeoutSeconds,
        public array $expectedStatus,
        public int $maxRedirects,
        public StoreResponse $storeResponse,
        public string $scheme,
        public string $host,
        public int $port,
        public string $displayUrl,
        public bool $hasHeaders,
        public int $headerCount,
        public bool $hasBody,
    ) {
    }

    /**
     * Dieselbe Konfiguration mit anderer Anzeige-URL. Nur für {@see \Meridian\Settings\SettingsService} beim
     * Verschärfen auf `http.display_path = hidden`; der Text kommt dort aus {@see UrlDisplay::hideStored()}.
     */
    public function withDisplayUrl(string $displayUrl): self
    {
        return new self(
            $this->method,
            $this->timeoutSeconds,
            $this->expectedStatus,
            $this->maxRedirects,
            $this->storeResponse,
            $this->scheme,
            $this->host,
            $this->port,
            $displayUrl,
            $this->hasHeaders,
            $this->headerCount,
            $this->hasBody,
        );
    }

    /** Schema + Host (+ Port, wenn nicht Standard): das Ziel ohne jeden geheimen Teil. */
    public function target(): string
    {
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
        $default = $this->port === ($this->scheme === 'https' ? 443 : 80);

        return $this->scheme . '://' . $host . ($default ? '' : ':' . $this->port);
    }

    /** Erwartete Statuscodes als Text, z. B. `200-299,404`. */
    public function expectedStatusText(): string
    {
        $parts = [];
        foreach ($this->expectedStatus as [$from, $to]) {
            $parts[] = $from === $to ? (string) $from : $from . '-' . $to;
        }

        return implode(',', $parts);
    }

    /**
     * Zerlegt den Text der API (`200-299,404`) in Bereiche.
     *
     * @return list<array{0: int, 1: int}>|null null bei ungültigem Text oder von > bis
     */
    public static function parseExpectedStatus(string $text): ?array
    {
        if (preg_match('/^[1-5][0-9]{2}(-[1-5][0-9]{2})?(,[1-5][0-9]{2}(-[1-5][0-9]{2})?){0,19}$/D', $text) !== 1) {
            return null;
        }
        $ranges = [];
        foreach (explode(',', $text) as $part) {
            $bounds = explode('-', $part);
            $from = (int) $bounds[0];
            $to = (int) ($bounds[1] ?? $bounds[0]);
            if ($from > $to) {
                return null;
            }
            $ranges[] = [$from, $to];
        }

        return $ranges;
    }

    public function toJson(): string
    {
        return json_encode([
            'v' => self::VERSION,
            'http' => [
                'method' => $this->method->value,
                'timeout_seconds' => $this->timeoutSeconds,
                'expected_status' => $this->expectedStatus,
                'max_redirects' => $this->maxRedirects,
                'store_response' => $this->storeResponse->value,
                'target' => ['scheme' => $this->scheme, 'host' => $this->host, 'port' => $this->port],
                'display_url' => $this->displayUrl,
                'display_v' => UrlDisplay::VERSION,
                'has_headers' => $this->hasHeaders,
                'header_count' => $this->headerCount,
                'has_body' => $this->hasBody,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Liest `config_json` streng: fehlt `v` oder ist etwas unbekannt, ist der Datensatz ungültig (keine stillen
     * Standardwerte).
     *
     * @throws InvalidJobConfig
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidJobConfig();
        }
        if (!is_array($data) || array_keys($data) !== ['v', 'http'] && array_keys($data) !== ['http', 'v']) {
            throw new InvalidJobConfig();
        }
        $http = $data['http'] ?? null;
        if (($data['v'] ?? null) !== self::VERSION || !is_array($http)) {
            throw new InvalidJobConfig();
        }
        $keys = array_keys($http);
        sort($keys);
        if ($keys !== self::HTTP_KEYS) {
            throw new InvalidJobConfig();
        }

        $method = is_string($http['method']) ? HttpMethod::tryFrom($http['method']) : null;
        $store = is_string($http['store_response']) ? StoreResponse::tryFrom($http['store_response']) : null;
        $timeout = $http['timeout_seconds'];
        $redirects = $http['max_redirects'];
        $count = $http['header_count'];
        $target = $http['target'];
        if ($method === null || $store === null || !is_int($timeout) || $timeout < 1 || $timeout > 3600
            || !is_int($redirects) || $redirects < 0 || $redirects > 5
            || !is_int($count) || $count < 0 || $count > 30
            || !is_bool($http['has_headers']) || $http['has_headers'] !== ($count > 0) || !is_bool($http['has_body'])
            || $http['display_v'] !== UrlDisplay::VERSION || !is_string($http['display_url']) || strlen($http['display_url']) > 2200
            || !is_array($target) || array_keys($target) !== ['scheme', 'host', 'port']) {
            throw new InvalidJobConfig();
        }
        $scheme = $target['scheme'];
        $host = $target['host'];
        $port = $target['port'];
        if (($scheme !== 'http' && $scheme !== 'https') || !is_string($host) || !is_int($port) || $port < 1 || $port > 65535
            || !(UrlPolicy::isValidHostname($host) || filter_var($host, FILTER_VALIDATE_IP) !== false)) {
            throw new InvalidJobConfig();
        }

        return new self(
            $method,
            $timeout,
            self::ranges($http['expected_status']),
            $redirects,
            $store,
            $scheme,
            $host,
            $port,
            $http['display_url'],
            $http['has_headers'],
            $count,
            $http['has_body'],
        );
    }

    /**
     * @return list<array{0: int, 1: int}>
     *
     * @throws InvalidJobConfig
     */
    private static function ranges(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > self::MAX_STATUS_RANGES) {
            throw new InvalidJobConfig();
        }
        $ranges = [];
        foreach ($value as $range) {
            if (!is_array($range) || !array_is_list($range) || count($range) !== 2 || !is_int($range[0]) || !is_int($range[1])
                || $range[0] < 100 || $range[1] > 599 || $range[0] > $range[1]) {
                throw new InvalidJobConfig();
            }
            $ranges[] = [$range[0], $range[1]];
        }

        return $ranges;
    }
}
