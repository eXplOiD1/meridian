<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell\Docker;

use Meridian\Runner\Shell\ExecFailed;

/**
 * Spricht mit dem Docker-Socket-Proxy (wollomatic, docs/decisions/0004 E10/§5.5) — **nur** über einen Unix-Socket,
 * nie über TCP. JSON-Aufrufe über curl (`CURLOPT_UNIX_SOCKET_PATH`, Zeitlimit 10 s, Antwort ≤ 1 MiB); der
 * hochgestufte Strom für `exec/{id}/start` über einen eigenen Socket ({@see self::openExecStream()}).
 *
 * Nur die Pfade, die der Proxy erlaubt: `/_ping`, `containers/{name}/json`, `containers/{name}/exec`,
 * `exec/{id}/json`, `exec/{id}/start`. Fehler sind immer {@see ExecFailed} mit festem Text — nie `curl_error()`,
 * nie ein Antworttext von Docker.
 */
final class DockerProxyClient
{
    public const MIN_API_MINOR = 41;
    public const TIMEOUT_SECONDS = 10;
    public const CONNECT_TIMEOUT_SECONDS = 5;
    public const MAX_RESPONSE_BYTES = 1048576;
    private const MAX_HEADER_BYTES = 16384;
    private const SOCKET_PATH = '#^/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$#D';

    private ?string $apiPrefix = null;
    /** Antwort über {@see self::MAX_RESPONSE_BYTES} (vom Schreib-Rückruf gesetzt). */
    private bool $tooLarge = false;
    /** @var non-empty-string */
    private readonly string $socketPath;

    /** @param int $timeoutSeconds Zeitlimit je Aufruf (Tests kürzer) */
    public function __construct(string $socketPath, private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS)
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > self::TIMEOUT_SECONDS || $socketPath === '') {
            throw new InvalidDockerProxy();
        }
        if (preg_match(self::SOCKET_PATH, $socketPath) !== 1 || preg_match('#(^|/)\.\.?(/|$)#', $socketPath) === 1) {
            throw new InvalidDockerProxy();
        }
        $this->socketPath = $socketPath;
    }

    /**
     * Aus `MERIDIAN_DOCKER_PROXY`: nur `unix:///absoluter/pfad`. `tcp://`, `http://` und alles andere wird beim Start
     * abgelehnt (E10). Leer/nicht gesetzt → null (kein Docker-Ausführungsort).
     */
    public static function fromEnvironment(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!str_starts_with($value, 'unix://')) {
            throw new InvalidDockerProxy();
        }

        return new self(substr($value, strlen('unix://')));
    }

    public function socketPath(): string
    {
        return $this->socketPath;
    }

    /**
     * `/v1.NN`: Version des Daemons aus `GET /_ping` (Header `API-Version`), mindestens 1.41. Ohne Header 1.41.
     *
     * @throws ExecFailed
     */
    public function apiPrefix(): string
    {
        if ($this->apiPrefix !== null) {
            return $this->apiPrefix;
        }
        $response = $this->call('GET', '/_ping', null);
        if ($response['status'] !== 200) {
            throw self::statusFailure($response['status']);
        }
        $minor = self::MIN_API_MINOR;
        $version = $response['headers']['api-version'] ?? null;
        if ($version !== null) {
            if (preg_match('/^1\.([0-9]{2})$/D', $version, $m) !== 1) {
                throw ExecFailed::proxyError();
            }
            $minor = (int) $m[1];
            if ($minor < self::MIN_API_MINOR) {
                throw new ExecFailed(ExecFailed::API_TOO_OLD, false);
            }
        }

        return $this->apiPrefix = '/v1.' . $minor;
    }

    /**
     * JSON-Aufruf. `$path` ohne Versionspräfix (z. B. `/containers/x/json`); nur aus festen Teilen und validierten
     * Namen/IDs gebaut.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array{status: int, json: array<array-key, mixed>|null}
     *
     * @throws ExecFailed
     */
    public function json(string $method, string $path, #[\SensitiveParameter] ?array $body = null): array
    {
        $encoded = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = $this->call($method, $this->apiPrefix() . $path, $encoded);
        return ['status' => $response['status'], 'json' => self::decode($response['body'])];
    }

    /**
     * `POST /exec/{id}/start` mit `Connection: Upgrade`, `Upgrade: tcp` auf einem eigenen Socket. Liefert den
     * hochgestuften Strom (Docker-Mehrfachstrom) und bereits gelesene Bytes nach den Kopfzeilen.
     *
     * @return array{0: resource, 1: string}
     *
     * @throws ExecFailed
     */
    public function openExecStream(string $execId): array
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $execId) !== 1) {
            throw ExecFailed::protocol();
        }
        $path = $this->apiPrefix() . '/exec/' . $execId . '/start';
        $body = '{"Detach":false,"Tty":false}';
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('unix://' . $this->socketPath, $errno, $errstr, (float) self::CONNECT_TIMEOUT_SECONDS);
        if ($socket === false) {
            throw ExecFailed::proxyUnreachable();
        }
        stream_set_timeout($socket, $this->timeoutSeconds);
        $request = 'POST ' . $path . " HTTP/1.1\r\nHost: docker\r\nUser-Agent: Meridian\r\nContent-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\nConnection: Upgrade\r\nUpgrade: tcp\r\n\r\n" . $body;
        if (!self::writeAll($socket, $request)) {
            fclose($socket);
            throw ExecFailed::connectionLost();
        }

        $buffer = '';
        $deadline = microtime(true) + (float) $this->timeoutSeconds;
        while (!str_contains($buffer, "\r\n\r\n")) {
            if (strlen($buffer) > self::MAX_HEADER_BYTES || microtime(true) > $deadline) {
                fclose($socket);
                throw ExecFailed::protocol();
            }
            $chunk = fread($socket, 4096);
            if ($chunk === false || ($chunk === '' && feof($socket))) {
                fclose($socket);
                throw ExecFailed::connectionLost();
            }
            $buffer .= $chunk;
        }
        $end = (int) strpos($buffer, "\r\n\r\n");
        $head = substr($buffer, 0, $end);
        $rest = substr($buffer, $end + 4);
        if (preg_match('#^HTTP/1\.[01] ([0-9]{3})#', $head, $m) !== 1) {
            fclose($socket);
            throw ExecFailed::protocol();
        }
        $status = (int) $m[1];
        if ($status !== 101 && $status !== 200) {
            fclose($socket);
            throw self::statusFailure($status);
        }
        stream_set_blocking($socket, false);

        return [$socket, $rest];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decode(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        try {
            return self::arrayOrNull(json_decode($body, true, 32, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function responseTooLarge(): bool
    {
        return $this->tooLarge;
    }

    /** Feste Zuordnung eines HTTP-Status zu einer Notiz (§5.3). */
    public static function statusFailure(int $status): ExecFailed
    {
        return match (true) {
            $status === 403 => ExecFailed::proxyDenied(),
            $status === 404, $status === 409 => ExecFailed::containerMissing(),
            default => ExecFailed::proxyError(),
        };
    }

    /**
     * @param resource $socket
     */
    public static function writeAll($socket, #[\SensitiveParameter] string $data): bool
    {
        $deadline = microtime(true) + (float) self::TIMEOUT_SECONDS;
        while ($data !== '') {
            $written = @fwrite($socket, $data);
            if ($written === false || ($written === 0 && microtime(true) > $deadline)) {
                return false;
            }
            $data = substr($data, $written);
        }

        return true;
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     *
     * @throws ExecFailed
     */
    private function call(string $method, string $path, #[\SensitiveParameter] ?string $body): array
    {
        if (!in_array($method, ['GET', 'POST'], true) || preg_match('#^(/v1\.[0-9]{2})?/[A-Za-z0-9_./-]+$#D', $path) !== 1) {
            throw ExecFailed::protocol();
        }
        $curl = curl_init();
        if ($curl === false) {
            throw ExecFailed::proxyUnreachable();
        }
        $received = '';
        $this->tooLarge = false;
        $headers = [];
        $options = [
            CURLOPT_UNIX_SOCKET_PATH => $this->socketPath,
            CURLOPT_URL => 'http://docker' . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_NOPROXY => '*',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT_SECONDS, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_NOSIGNAL => true,
            // Kein „Expect: 100-continue“: der Proxy soll den Körper sofort bekommen.
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'],
            CURLOPT_USERAGENT => 'Meridian',
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function (\CurlHandle $handle, string $chunk) use (&$received): int {
                if (strlen($received) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $this->tooLarge = true;

                    return 0;
                }
                $received .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if (!curl_setopt_array($curl, $options)) {
            curl_close($curl);
            throw ExecFailed::proxyUnreachable();
        }
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($this->responseTooLarge()) {
            throw ExecFailed::protocol();
        }
        if ($ok === false || $status === 0) {
            // Nie curl_error(): Socket fehlt, abgelehnt, Zeitlimit — eine feste Meldung.
            throw ExecFailed::proxyUnreachable();
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $received];
    }
}
