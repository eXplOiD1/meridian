<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Monitor\Probe;

use Meridian\Job\HttpJobConfig;
use Meridian\Monitor\MonitorKind;
use Meridian\Monitor\MonitorTarget;
use Meridian\Notify\NullHeartbeat;
use Meridian\Runner\Http\HttpRunner;
use Meridian\Runner\Http\HttpTransport;
use Meridian\Runner\Http\InvalidUrl;
use Meridian\Runner\Http\TargetBlocked;
use Meridian\Runner\Http\TargetGuard;
use Meridian\Runner\Http\TargetUnresolvable;
use Meridian\Runner\Http\TransportError;
use Meridian\Runner\Http\TransportRequest;
use Meridian\Runner\Http\UrlPolicy;

/**
 * Aktive Prüfung eines Ziels (ADR 0018): Web-Adresse, TCP-Port oder Ping. Vor jeder Verbindung läuft der `TargetGuard`
 * (auflösen, jede Adresse prüfen, festhalten; Freigaben je Kategorie der Überwachung); verbunden wird nur zu einer geprüften
 * Adresse, nie zum Namen. Weiterleitungen werden nicht verfolgt (3xx zählt nach den erwarteten Codes), TLS wird immer geprüft,
 * es gehen keine Header und kein Body. Das Ergebnis trägt nur feste Texte.
 */
final class NetworkProbe implements Prober
{
    public const TIMEOUT_SECONDS = 10;
    public const NOTE_TCP = 'Verbindung fehlgeschlagen: Der Port ist nicht erreichbar oder hat die Verbindung abgelehnt.';
    public const NOTE_PING = 'Keine Antwort auf den Ping.';
    public const NOTE_PING_UNAVAILABLE = 'Ping ist in dieser Installation nicht verfügbar: Das Programm „ping“ fehlt oder darf keine Pakete senden.';
    public const NOTE_TARGET_UNREADABLE = 'Das gespeicherte Ziel ist nicht lesbar. Ziel in der Überwachung neu eingeben.';

    /** @var \Closure(string, int, int): bool */
    private readonly \Closure $connector;

    /** @var \Closure(string): ?bool */
    private readonly \Closure $pinger;

    /**
     * @param (\Closure(string, int, int): bool)|null $connector verbindet zu IP und Port mit Zeitlimit (Sekunden); Standard: echter TCP-Aufbau
     * @param (\Closure(string): ?bool)|null          $pinger    true = Antwort, false = keine Antwort, null = Ping nicht verfügbar; Standard: das Programm „ping“
     */
    public function __construct(
        private readonly TargetGuard $guard,
        private readonly HttpTransport $transport,
        private readonly UrlPolicy $urls = new UrlPolicy(),
        ?\Closure $connector = null,
        ?\Closure $pinger = null,
    ) {
        $this->connector = $connector ?? self::connect(...);
        $this->pinger = $pinger ?? self::ping(...);
    }

    #[\Override]
    public function probe(MonitorKind $kind, #[\SensitiveParameter] string $target, ?int $categoryId, string $expectedStatus): ProbeResult
    {
        try {
            $url = MonitorTarget::parse($kind, $target, $this->urls);
            $pinned = $this->guard->pin($url, $categoryId);
        } catch (InvalidUrl) {
            return ProbeResult::failed(self::NOTE_TARGET_UNREADABLE);
        } catch (TargetBlocked|TargetUnresolvable $e) {
            return ProbeResult::failed($e->getMessage());
        }

        $started = hrtime(true);
        if ($kind === MonitorKind::Http) {
            return $this->http($pinned, $expectedStatus);
        }
        if ($kind === MonitorKind::Tcp) {
            foreach ($pinned->ips as $ip) {
                if (($this->connector)($ip, $url->port, self::TIMEOUT_SECONDS)) {
                    return ProbeResult::ok(self::elapsed($started));
                }
            }

            return ProbeResult::failed(self::NOTE_TCP);
        }
        foreach ($pinned->ips as $ip) {
            $answered = ($this->pinger)($ip);
            if ($answered === null) {
                return ProbeResult::failed(self::NOTE_PING_UNAVAILABLE);
            }
            if ($answered) {
                return ProbeResult::ok(self::elapsed($started));
            }
        }

        return ProbeResult::failed(self::NOTE_PING);
    }

    private function http(\Meridian\Runner\Http\PinnedTarget $pinned, string $expectedStatus): ProbeResult
    {
        $ranges = HttpJobConfig::parseExpectedStatus($expectedStatus);
        if ($ranges === null) {
            return ProbeResult::failed(self::NOTE_TARGET_UNREADABLE);
        }
        $response = $this->transport->send(new TransportRequest('GET', $pinned, [], null, self::TIMEOUT_SECONDS * 1000), new NullHeartbeat());
        if ($response->error !== null) {
            return ProbeResult::failed(match ($response->error) {
                TransportError::Timeout => 'Zeitüberschreitung nach ' . self::TIMEOUT_SECONDS . ' s.',
                TransportError::Tls => HttpRunner::NOTE_TLS,
                TransportError::PinViolation => HttpRunner::NOTE_PIN,
                TransportError::HeadersTooLarge => HttpRunner::NOTE_HEADERS,
                TransportError::Aborted, TransportError::Connect => HttpRunner::NOTE_CONNECT,
                TransportError::Other => 'Übertragung fehlgeschlagen (curl-Fehler ' . ($response->errorCode ?? 0) . ').',
            });
        }
        foreach ($ranges as [$from, $to]) {
            if ($response->status >= $from && $response->status <= $to) {
                return ProbeResult::ok($response->durationMs);
            }
        }

        return ProbeResult::failed('Unerwarteter Statuscode ' . $response->status . ' (erwartet: ' . $expectedStatus . ').');
    }

    private static function elapsed(int|float $started): int
    {
        return max(0, intdiv((int) hrtime(true) - (int) $started, 1_000_000));
    }

    private static function connect(string $ip, int $port, int $timeout): bool
    {
        $address = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        // Warnungen nennen Adresse und Port; sie bleiben im Haus.
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client('tcp://' . $address . ':' . $port, $errno, $errstr, (float) $timeout);
        } finally {
            restore_error_handler();
        }
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /**
     * Das Programm „ping“ mit Argument-Array und fester Umgebung, nur mit einer bereits geprüften IP-Adresse.
     */
    private static function ping(string $ip): ?bool
    {
        $binary = null;
        foreach (['/bin/ping', '/usr/bin/ping', '/sbin/ping'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $binary = $candidate;
                break;
            }
        }
        if ($binary === null) {
            return null;
        }
        $command = [$binary, '-c', '1', '-W', '5', ...(str_contains($ip, ':') ? ['-6'] : []), $ip];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C']);
        if (!is_resource($process)) {
            return null;
        }
        $deadline = hrtime(true) + (self::TIMEOUT_SECONDS + 1) * 1_000_000_000;
        $exit = null;
        while (hrtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            usleep(50_000);
        }
        if ($exit === null) {
            proc_terminate($process, 9);
            proc_close($process);

            return false;
        }
        proc_close($process);

        // 0 = Antwort, 1 = keine Antwort, alles andere (z. B. 2: keine Berechtigung, unbekannte Option) = nicht verfügbar.
        return match ($exit) {
            0 => true,
            1 => false,
            default => null,
        };
    }
}
