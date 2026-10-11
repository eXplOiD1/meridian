<?php

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Runner\Http\HttpRunner;

/** Übersetzt feste Diagnosemeldungen; benutzerdefinierte Inhalte werden nicht umgeschrieben. */
final class MessageDetail
{
    private const ENGLISH = [
        HttpRunner::NOTE_TLS => 'TLS verification failed: the certificate is invalid, expired, or issued for a different name.',
        HttpRunner::NOTE_CONNECT => 'Connection failed: the target is unreachable or refused the connection.',
        HttpRunner::NOTE_ABORTED => 'Cancelled: the run was stopped.',
        HttpRunner::NOTE_NOT_HTTP => 'Failed: the job no longer exists or is no longer an HTTP job.',
        HttpRunner::NOTE_BODY_FOR_GET => 'The saved request contains a body, but GET/HEAD does not send one. Replace the request or change the method.',
        HttpRunner::NOTE_PIN => 'Target blocked: the connection used an unverified address and was stopped.',
        HttpRunner::NOTE_HEADERS => 'The response headers exceed 32 KiB.',
        HttpRunner::NOTE_REDIRECT_INVALID => 'Invalid redirect: the target is not an allowed HTTP(S) address.',
        HttpRunner::NOTE_REDIRECT_DOWNGRADE => 'Invalid redirect: HTTPS to HTTP redirects are not allowed.',
        HttpRunner::NOTE_REDIRECT_BODY => 'Invalid redirect: a request body is not forwarded to a different server (307/308).',
        HttpRunner::NOTE_BODY_LIMIT => 'Response stopped after 10 MiB.',
        'Die Prüfung ist fehlgeschlagen.' => 'The check failed.',
        'Noch kein Lebenszeichen erhalten.' => 'No heartbeat has been received yet.',
        'Zeitlimit überschritten.' => 'Time limit exceeded.',
        'Das Lebenszeichen ist wieder da.' => 'The heartbeat has returned.',
        'Die Prüfung ist wieder erfolgreich.' => 'The check is succeeding again.',
    ];

    public static function english(string $detail): string
    {
        if (isset(self::ENGLISH[$detail])) {
            return self::ENGLISH[$detail];
        }
        // Übersetzt auch kombinierte Runner-Notizen, ohne gespeicherte Daten zu ändern.
        $translated = strtr($detail, self::ENGLISH);
        $patterns = [
            '/Es kam noch kein Lebenszeichen \(erwartet etwa alle ([0-9]+) Min\)\./' => 'No heartbeat received yet (expected about every $1 min).',
            '/Das letzte Lebenszeichen war um (.+) \(UTC\); erwartet etwa alle ([0-9]+) Min\./' => 'The last heartbeat was at $1 (UTC); expected about every $2 min.',
            '/Das Zertifikat ist am (.+) \(UTC\) abgelaufen\./' => 'The certificate expired on $1 (UTC).',
            '/Das Zertifikat läuft am (.+) \(UTC\) ab, in (-?[0-9]+) Tag\(en\)\./' => 'The certificate expires on $1 (UTC), in $2 day(s).',
            '/Letztes Lebenszeichen: (.+)/' => 'Last heartbeat: $1',
            '/Zeitlimit auf das Maximum von ([0-9]+) s begrenzt\./' => 'Time limit capped at the maximum of $1 s.',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $translated = preg_replace($pattern, $replacement, $translated) ?? $translated;
        }

        return $translated;
    }
}
