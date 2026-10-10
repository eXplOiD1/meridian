<?php

declare(strict_types=1);

namespace Meridian\Http;

/**
 * Ausgabekanal eines Server-Sent-Events-Stroms (ADR 0004 §6.1). Im Betrieb {@see PhpSseChannel} (echo + flush,
 * `connection_aborted()`), in Tests ein aufzeichnender Kanal mit steuerbarer Uhr.
 */
interface SseChannel
{
    /** Vor dem ersten Ereignis: Ausgabepuffer leeren, Zeitlimit setzen, Verbindungsabbruch selbst behandeln. */
    public function open(): void;

    /**
     * Schreibt einen fertigen Rahmen (bereits maskiert und JSON-kodiert) und leert den Puffer sofort.
     *
     * @return bool false = der Client hat die Verbindung getrennt; der Strom endet dann
     */
    public function send(string $frame): bool;

    public function pause(int $milliseconds): void;

    /**
     * Aufräumen auch dann, wenn der Prozess hart endet (Zeitlimit): `finally` läuft dann nicht.
     *
     * @param callable(): void $cleanup
     */
    public function atShutdown(callable $cleanup): void;
}
