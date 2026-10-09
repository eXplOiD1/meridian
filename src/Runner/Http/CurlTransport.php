<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Runner\Heartbeat;

/**
 * Ein Hop über curl_multi (docs/decisions/0003, §5.4).
 *
 *  - Verbindet nur zu den festgehaltenen Adressen (`CURLOPT_RESOLVE`); zweite Absicherung: die verbundene
 *    Adresse wird geprüft (`CURLOPT_PREREQFUNCTION` ab PHP 8.4 vor dem Senden, sonst so früh wie möglich im
 *    Fortschritts- und Header-Rückruf) — sonst Abbruch.
 *  - Keine Weiterleitungen, nur http/https, Proxy-Umgebung ignoriert, TLS-Prüfung immer an, keine Cookies,
 *    keine Entpackung, keine wiederverwendete Verbindung.
 *  - Antwort schon beim Lesen begrenzt: die ersten {@see self::KEEP_BODY_BYTES} behalten, den Rest nur zählen,
 *    über {@see self::MAX_BODY_BYTES} abbrechen; Header höchstens {@see self::MAX_HEADER_BYTES}.
 *  - `Heartbeat::beat()` nach jedem Durchgang der Schleife (`curl_multi_select` mit 1 s), bei `false` sofort Abbruch.
 *  - Nie `curl_error()`: dessen Text enthält die URL. Fehler nur als {@see TransportError} und Fehlercode.
 */
final class CurlTransport implements HttpTransport
{
    /** 64 KiB Ausgabe plus Überhang fürs Maskieren an der Schnittkante. */
    public const KEEP_BODY_BYTES = 81920;
    public const MAX_BODY_BYTES = 10485760;
    public const MAX_HEADER_BYTES = 32768;
    public const MAX_CONNECT_MS = 10000;

    private const SELECT_SECONDS = 1.0;

    /** Eigene Obergrenze über dem curl-Zeitlimit, falls curl selbst nicht abbricht. */
    private const GRACE_MS = 2000;

    /** curl-Fehlercodes → Art des Fehlers. */
    private const TIMEOUT_CODES = [28];
    private const CONNECT_CODES = [5, 6, 7, 52, 55, 56, 95];
    private const TLS_CODES = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98];
    private const WRITE_ERROR = 23;

    public function __construct(private readonly string $userAgent = 'Meridian')
    {
    }

    #[\Override]
    public function send(#[\SensitiveParameter] TransportRequest $request, Heartbeat $heartbeat): TransportResponse
    {
        $started = (int) hrtime(true);
        $hop = new CurlHop($request->target->ips, $request->target->url->port);

        $ch = curl_init();
        if ($ch === false) {
            return TransportResponse::failure(TransportError::Other, self::elapsedMs($started));
        }
        $mh = curl_multi_init();
        try {
            if (!curl_setopt_array($ch, $this->options($request) + $this->callbacks($hop))) {
                return TransportResponse::failure(TransportError::Other, self::elapsedMs($started));
            }
            curl_multi_add_handle($mh, $ch);

            $aborted = false;
            $overdue = false;
            do {
                $code = curl_multi_exec($mh, $running);
                if ($code !== CURLM_OK) {
                    break;
                }
                if (!$heartbeat->beat()) {
                    $aborted = true;
                    break;
                }
                if (self::elapsedMs($started) > $request->timeoutMs + self::GRACE_MS) {
                    $overdue = true;
                    break;
                }
                if ($running > 0 && curl_multi_select($mh, self::SELECT_SECONDS) === -1) {
                    usleep(10_000);
                }
            } while ($running > 0);

            $info = curl_multi_info_read($mh);
            $errno = is_array($info) && isset($info['result']) && is_int($info['result']) ? $info['result'] : null;
            $status = filter_var(curl_getinfo($ch, CURLINFO_RESPONSE_CODE), FILTER_VALIDATE_INT);
            $hop->status = is_int($status) && $status > 0 ? $status : $hop->status;
            curl_multi_remove_handle($mh, $ch);
        } finally {
            curl_multi_close($mh);
        }
        $duration = self::elapsedMs($started);

        if ($aborted) {
            return TransportResponse::failure(TransportError::Aborted, $duration);
        }
        if ($hop->abort !== null) {
            return TransportResponse::failure($hop->abort, $duration);
        }
        $error = null;
        if ($overdue) {
            $error = TransportError::Timeout;
        } elseif ($errno === null) {
            $error = TransportError::Other;
        } elseif ($errno !== 0 && !($errno === self::WRITE_ERROR && $hop->bodyLimitReached)) {
            $error = self::classify($errno);
        }

        return new TransportResponse(
            $hop->status,
            $error,
            $error === null ? null : $errno,
            $hop->location,
            $hop->contentType,
            $hop->body,
            $hop->bodyBytes,
            $hop->bodyLimitReached,
            $duration,
        );
    }

    /**
     * Die festen curl-Optionen eines Hops, ohne Rückrufe (für den Optionen-Test öffentlich).
     *
     * @return array<int, mixed>
     */
    public function options(#[\SensitiveParameter] TransportRequest $request): array
    {
        $url = $request->target->url;
        $timeout = max(1, $request->timeoutMs);
        $headers = [];
        foreach ($request->headers() as [$name, $value]) {
            $headers[] = $name . ': ' . $value;
        }
        // curl setzt sonst bei großen Bodies „Expect: 100-continue“ (eigene Expect-Header sind verboten).
        $headers[] = 'Expect:';

        $options = [
            CURLOPT_URL => $url->toUrl(),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            // Proxy-Umgebung (HTTP_PROXY, https_proxy, ALL_PROXY …) ignorieren (O12): sonst ginge die Anfrage samt
            // Geheimnissen an den Proxy, und die Adressprüfung wäre wirkungslos.
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(self::MAX_CONNECT_MS, $timeout),
            CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_UNRESTRICTED_AUTH => false,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_NOPROGRESS => false,
        ];
        $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        if (!$url->isIpLiteral) {
            // Nur die geprüften Adressen; curl fragt kein DNS (kein Rebinding). Host exakt wie in der URL.
            $ips = array_map(static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $request->target->ips);
            $options[CURLOPT_RESOLVE] = [$url->host . ':' . $url->port . ':' . implode(',', $ips)];
        }

        switch ($request->method) {
            case 'HEAD':
                $options[CURLOPT_NOBODY] = true;
                break;
            case 'GET':
                $options[CURLOPT_HTTPGET] = true;
                break;
            case 'POST':
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $request->body() ?? '';
                break;
            default:
                $options[CURLOPT_CUSTOMREQUEST] = $request->method;
                $body = $request->body();
                if ($body !== null) {
                    $options[CURLOPT_POSTFIELDS] = $body;
                }
        }

        return $options;
    }

    /**
     * @return array<int, callable>
     */
    private function callbacks(CurlHop $hop): array
    {
        $checkPeer = static function (\CurlHandle $ch) use ($hop): bool {
            if ($hop->peerChecked) {
                return true;
            }
            $ip = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
            $port = filter_var(curl_getinfo($ch, CURLINFO_PRIMARY_PORT), FILTER_VALIDATE_INT);
            if (!is_string($ip) || $ip === '') {
                return true;
            }
            $hop->peerChecked = true;
            if (!$hop->allowsPeer($ip, is_int($port) ? $port : 0)) {
                $hop->abort = TransportError::PinViolation;

                return false;
            }

            return true;
        };

        $callbacks = [
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $ch, #[\SensitiveParameter] string $line) use ($hop, $checkPeer): int {
                $hop->headerBytes += strlen($line);
                if ($hop->headerBytes > self::MAX_HEADER_BYTES) {
                    $hop->abort = TransportError::HeadersTooLarge;

                    return 0;
                }
                if (!$checkPeer($ch)) {
                    return 0;
                }
                if (preg_match('~^HTTP/[0-9.]+\s+([1-5][0-9]{2})~', $line, $m) === 1) {
                    // Neue Statuszeile (z. B. nach „100 Continue“): Werte der vorigen Antwort verwerfen.
                    $hop->status = (int) $m[1];
                    $hop->location = null;
                    $hop->contentType = null;
                } elseif (preg_match('/^location:[ \t]*(.*?)[ \t]*\r?\n?$/i', $line, $m) === 1) {
                    $hop->location = $m[1];
                } elseif (preg_match('/^content-type:[ \t]*(.*?)[ \t]*\r?\n?$/i', $line, $m) === 1) {
                    $hop->contentType = substr($m[1], 0, 200);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $ch, #[\SensitiveParameter] string $data) use ($hop): int {
                $length = strlen($data);
                $room = self::KEEP_BODY_BYTES - strlen($hop->body);
                if ($room > 0) {
                    $hop->body .= substr($data, 0, $room);
                }
                if ($hop->bodyBytes + $length > self::MAX_BODY_BYTES) {
                    // Über 10 MiB: nicht weiter lesen (H3). Bewertet wird trotzdem nach dem Statuscode.
                    $hop->bodyBytes = self::MAX_BODY_BYTES;
                    $hop->bodyLimitReached = true;

                    return 0;
                }
                $hop->bodyBytes += $length;

                return $length;
            },
            CURLOPT_XFERINFOFUNCTION => static function (\CurlHandle $ch) use ($checkPeer): int {
                return $checkPeer($ch) ? 0 : 1;
            },
        ];
        $prereq = self::intConstant('CURLOPT_PREREQFUNCTION');
        $prereqOk = self::intConstant('CURL_PREREQFUNC_OK');
        $prereqAbort = self::intConstant('CURL_PREREQFUNC_ABORT');
        if ($prereq !== null && $prereqOk !== null && $prereqAbort !== null) {
            // PHP ≥ 8.4: nach dem Verbinden, vor dem Senden der Anfrage — Header und Body gehen nie an eine
            // ungeprüfte Adresse.
            $callbacks[$prereq] = static function (\CurlHandle $ch, string $primaryIp, string $localIp, int $primaryPort) use ($hop, $prereqOk, $prereqAbort): int {
                $hop->peerChecked = true;
                if (!$hop->allowsPeer($primaryIp, $primaryPort)) {
                    $hop->abort = TransportError::PinViolation;

                    return $prereqAbort;
                }

                return $prereqOk;
            };
        }

        return $callbacks;
    }

    /** Wert einer curl-Konstante, die es erst in neueren PHP-Versionen gibt; null, wenn sie fehlt. */
    private static function intConstant(string $name): ?int
    {
        if (!defined($name)) {
            return null;
        }
        $value = filter_var(constant($name), FILTER_VALIDATE_INT);

        return is_int($value) ? $value : null;
    }

    private static function classify(int $errno): TransportError
    {
        return match (true) {
            in_array($errno, self::TIMEOUT_CODES, true) => TransportError::Timeout,
            in_array($errno, self::CONNECT_CODES, true) => TransportError::Connect,
            in_array($errno, self::TLS_CODES, true) => TransportError::Tls,
            default => TransportError::Other,
        };
    }

    private static function elapsedMs(int $started): int
    {
        return intdiv((int) hrtime(true) - $started, 1_000_000);
    }
}
