<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Runner\Heartbeat;

/**
 * Sendet genau **einen** Hop an ein geprüftes, festgehaltenes Ziel (keine Weiterleitungen, kein eigenes DNS).
 * Die Hop-Schleife, die SSRF-Prüfung je Hop und die Bewertung macht {@see HttpRunner}.
 *
 * Wirft nie: Fehler stehen als {@see TransportError} in der Antwort. Ruft während des Wartens mindestens alle
 * {@see Heartbeat::MAX_INTERVAL_SECONDS} Sekunden {@see Heartbeat::beat()} auf und bricht bei `false` sofort ab.
 */
interface HttpTransport
{
    public function send(#[\SensitiveParameter] TransportRequest $request, Heartbeat $heartbeat): TransportResponse;
}
