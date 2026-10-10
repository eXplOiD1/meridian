<?php

declare(strict_types=1);

namespace Meridian\Http;

/**
 * SSE-Kanal im Betrieb (FrankenPHP): ohne Pufferung, mit Erkennung des Verbindungsabbruchs nach jedem `flush()`.
 */
final class PhpSseChannel implements SseChannel
{
    /**
     * Obergrenze je Verbindung (ADR 0004 §6.1 Schritt 9): 60 s Strom plus Spielraum für ein blockierendes `flush()`.
     * Die Belegung in `live_streams` läuft genauso lange ({@see LiveStreamSlots::TTL_SECONDS}).
     */
    public const TIME_LIMIT_SECONDS = 75;

    #[\Override]
    public function open(): void
    {
        // Ohne ignore_user_abort beendet PHP das Skript beim Schreiben auf eine geschlossene Verbindung, und das
        // `finally` (Belegung freigeben) liefe nicht. Der Abbruch wird stattdessen nach jedem flush() geprüft.
        ignore_user_abort(true);
        set_time_limit(self::TIME_LIMIT_SECONDS);
        while (ob_get_level() > 0) {
            if (!ob_end_flush()) {
                break;
            }
        }
    }

    #[\Override]
    public function send(string $frame): bool
    {
        echo $frame;
        flush();

        return connection_aborted() === 0;
    }

    #[\Override]
    public function pause(int $milliseconds): void
    {
        usleep(max(0, $milliseconds) * 1000);
    }

    #[\Override]
    public function atShutdown(callable $cleanup): void
    {
        register_shutdown_function($cleanup);
    }
}
