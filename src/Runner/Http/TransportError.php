<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Fehler eines Hops, ohne Text von curl (der enthält die URL). Feste Meldungen und Bewertung macht der Runner.
 */
enum TransportError: string
{
    /** Gesamtzeit oder Verbindungszeit überschritten. */
    case Timeout = 'timeout';
    /** Verbindung abgelehnt, nicht erreichbar, abgebrochen, leere Antwort. */
    case Connect = 'connect';
    /** Zertifikat ungültig, abgelaufen, für einen anderen Namen, TLS-Handshake gescheitert. */
    case Tls = 'tls';
    /** Verbunden wurde zu einer Adresse, die nicht festgehalten war (zweite Absicherung neben CURLOPT_RESOLVE). */
    case PinViolation = 'pin_violation';
    /** Antwort-Header größer als {@see CurlTransport::MAX_HEADER_BYTES}. */
    case HeadersTooLarge = 'headers_too_large';
    /** `Heartbeat::beat()` lieferte false: der Lauf wurde abgebrochen. */
    case Aborted = 'aborted';
    /** Alles andere; der Runner nennt nur den Fehlercode. */
    case Other = 'other';
}
