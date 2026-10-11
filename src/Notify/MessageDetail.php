<?php

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Runner\Http\HttpRunner;
use Meridian\Monitor\Probe\NetworkProbe;
use Meridian\Runner\Shell\ExecFailed;
use Meridian\Runner\Shell\ShellRunner;
use Meridian\Runner\Shell\StreamMasker;
use Meridian\Runner\Shell\Docker\DockerExecution;

/** Übersetzt feste Diagnosemeldungen; benutzerdefinierte Inhalte werden nicht umgeschrieben. */
final class MessageDetail
{
    private const ENGLISH = [
        NetworkProbe::NOTE_TCP => 'Connection failed: the port is unreachable or refused the connection.',
        NetworkProbe::NOTE_PING => 'No reply to the ping.',
        NetworkProbe::NOTE_PING_UNAVAILABLE => 'Ping is unavailable: the ping program is missing or cannot send packets.',
        NetworkProbe::NOTE_TARGET_UNREADABLE => 'The saved target cannot be read. Enter the target again in Monitoring.',
        ShellRunner::NOTE_NOT_SHELL => 'Failed: the job no longer exists or is no longer a shell job.',
        ShellRunner::NOTE_FLOOD => 'Output exceeded 64 MiB: the process was stopped.',
        ShellRunner::NOTE_NO_INTERPRETER => 'Interpreter not found in the container.',
        ShellRunner::NOTE_HOST_UNAVAILABLE => 'Host execution is unavailable. Use a container execution target.',
        ShellRunner::NOTE_NO_DOCKER => 'Docker proxy not configured: set MERIDIAN_DOCKER_PROXY to unix:///… .',
        StreamMasker::NOTE_LONG_LINE => 'Long line without a line break: parts were hidden.',
        DockerExecution::NOTE_NO_SETSID => 'Container has no setsid: child processes may remain after cancellation.',
        ExecFailed::PROXY_UNREACHABLE => 'Docker proxy unreachable: check docker-proxy (docker compose ps).',
        ExecFailed::PROXY_DENIED => 'Docker proxy rejected the request: check the container name and proxy settings (docker compose logs docker-proxy).',
        ExecFailed::PROXY_ERROR => 'Unexpected Docker proxy error: check docker-proxy and Docker.',
        ExecFailed::CONTAINER_MISSING => 'Container missing or stopped: it must be running and its name must match exactly (docker ps).',
        ExecFailed::CONNECTION_LOST => 'Connection to the Docker proxy was lost.',
        ExecFailed::PROTOCOL => 'Container execution protocol error: run stopped. Does the container have /bin/sh, head and cut?',
        ExecFailed::HOST_NOT_SET_UP => 'Host execution not configured: check meridian-shell.socket.',
        ExecFailed::HOST_LOST => 'Host agent connection lost: run stopped. Check meridian-shell.socket (journalctl -u "meridian-shell@*").',
        ExecFailed::HOST_PROTOCOL => 'Host agent protocol error: run stopped. Check Meridian and agent versions.',
        ExecFailed::HOST_ISOLATION => 'The host agent refused execution: it must not run as root, inside a container, or with access to the key and database. Use meridian-run and the units in deploy/systemd.',
        ExecFailed::START_FAILED => 'The process could not be started.',
        ExecFailed::NO_PROCESS_GROUP => 'No separate process group (setsid): the run was refused for safety.',
        ExecFailed::INFRASTRUCTURE => 'Meridian containers cannot be execution targets. Choose another container.',
        ExecFailed::API_TOO_OLD => 'Docker API is too old (requires 1.41 or later). Update Docker on the server.',
        ExecFailed::INVALID_SPEC => 'Invalid execution target settings (name, user or working directory). Save the job again.',
        'Ausführungsort nicht (mehr) freigegeben: Ein Admin kann ihn unter Einstellungen → Ausführungsorte freigeben.' => 'Execution target is no longer allowed. An administrator can allow it under Settings → Execution targets.',
        'Benutzer für den Ausführungsort nicht (mehr) freigegeben: Ein Admin kann ihn unter Einstellungen → Ausführungsorte erlauben.' => 'User is no longer allowed for this execution target. An administrator can allow it under Settings → Execution targets.',
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
            '/Unerwarteter Statuscode ([0-9]+) \(erwartet: ([^)]+)\)\./' => 'Unexpected status code $1 (expected: $2).',
            '/ Weiterleitungen: ([0-9]+) erlaubt\./' => ' Redirects: $1 allowed.',
            '/Zeitlimit von ([0-9]+) s überschritten, Prozess beendet\./' => 'Time limit of $1 s exceeded: process stopped.',
            '/Beendet durch Signal ([0-9]+)\./' => 'Stopped by signal $1.',
            '/Beendet mit Exit-Code ([0-9]+)\./' => 'Exited with code $1.',
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
