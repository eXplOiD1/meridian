<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell\Docker;

use Meridian\Runner\Shell\ExecFailed;
use Meridian\Runner\Shell\ExecRef;
use Meridian\Runner\Shell\Execution;
use Meridian\Runner\Shell\Executor;
use Meridian\Runner\Shell\ExecSpec;
use Meridian\Runner\Shell\ShellTargetKind;

/**
 * Führt ein Skript per `docker exec` in einem **bestehenden, freigegebenen** Container aus — über den
 * Socket-Proxy, nie im Meridian-Container (docs/decisions/0004 E1/E2/E10, §5.5).
 *
 *  1. `GET /containers/{name}/json` → läuft er?
 *  2. `POST /containers/{name}/exec`: `Privileged:false`, `Tty:false`, Benutzer **aus der Freigabe**,
 *     Arbeitsverzeichnis, `Env` (Job-Variablen + `MERIDIAN_*` + `TZ`), `Cmd` = konstanter Wrapper + Interpreter +
 *     Nonce + Länge. Das Skript ist **nie** Teil von `Cmd` (Prozessliste, Docker-Log).
 *  3. `POST /exec/{id}/start` hochgestuft; das Skript geht mit genau `LEN` Bytes über stdin ({@see DockerExecution}).
 */
final class DockerExecExecutor implements Executor
{
    /**
     * `$1` Interpreter, `$2` Nonce, `$3` Länge, `$4` {@see self::PAYLOAD_WRAP}. Stellt eine eigene Prozessgruppe her,
     * ohne dass `setsid` forkt (das Exec-Kind ist dann kein Gruppenführer); ohne `setsid` läuft es ohne Gruppe.
     */
    public const WRAP = <<<'SH'
        if [ "$(cut -d' ' -f5 /proc/$$/stat 2>/dev/null)" != "$$" ] && command -v setsid >/dev/null 2>&1; then
          exec setsid /bin/sh -c "$4" meridian-run "$1" "$2" "$3"
        fi
        exec /bin/sh -c "$4" meridian-run "$1" "$2" "$3"
        SH;

    /**
     * Fehlt der Interpreter: Exit 127 ohne Ausgabe. Sonst erste stderr-Zeile `MERIDIAN-PGID <nonce> <pid> <pgrp>`
     * (wird nie ausgegeben), dann genau `$3` Bytes Skript an den Interpreter. `head -c` statt stdin-Ende: der Proxy
     * beendet eine hochgestufte Verbindung, sobald eine Richtung schließt.
     */
    public const PAYLOAD_WRAP = <<<'SH'
        command -v "$1" >/dev/null 2>&1 || exit 127
        printf 'MERIDIAN-PGID %s %s %s\n' "$2" "$$" "$(cut -d' ' -f5 /proc/$$/stat 2>/dev/null)" >&2
        head -c "$3" | "$1" -s
        SH;

    /** Kill-Exec (§5.5): `$1` Signal (TERM/KILL), `$2` validierte PGID bzw. PID. */
    public const KILL_GROUP = 'kill -s "$1" -- "-$2" 2>/dev/null || kill -s "$1" "$2"';
    public const KILL_PID = 'kill -s "$1" "$2"';

    /**
     * Ein Zeichen, das in Skripten nie vorkommt (NUL ist verboten): so löst kein Byte im Skript das Abkoppeln
     * (Standard Strg-P Strg-Q) aus.
     */
    public const DETACH_KEYS = 'ctrl-@';

    public function __construct(private readonly DockerProxyClient $proxy, private readonly float $pgidWaitSeconds = 10.0)
    {
    }

    #[\Override]
    public function start(#[\SensitiveParameter] ExecSpec $spec): Execution
    {
        if ($spec->target->kind !== ShellTargetKind::Docker || $spec->user === null) {
            throw ExecFailed::invalidSpec();
        }
        $script = $spec->script();
        if (str_contains($script, "\0")) {
            throw ExecFailed::invalidSpec();
        }
        $container = $spec->target->name;

        $inspect = $this->proxy->json('GET', '/containers/' . $container . '/json');
        if ($inspect['status'] !== 200) {
            throw DockerProxyClient::statusFailure($inspect['status']);
        }
        $state = $inspect['json']['State'] ?? null;
        if (!is_array($state) || ($state['Running'] ?? false) !== true) {
            throw ExecFailed::containerMissing();
        }

        $created = $this->proxy->json('POST', '/containers/' . $container . '/exec', self::createBody($spec));
        if ($created['status'] !== 201) {
            throw DockerProxyClient::statusFailure($created['status']);
        }
        $execId = $created['json']['Id'] ?? null;
        if (!is_string($execId) || !ExecRef::isValidExecId($execId)) {
            throw ExecFailed::protocol();
        }
        $ref = new ExecRef($container, $execId, null, $spec->user);

        [$socket, $rest] = $this->proxy->openExecStream($execId);

        return new DockerExecution($this->proxy, $socket, $rest, $ref, $spec->nonce, $script, $this->pgidWaitSeconds);
    }

    /**
     * Körper von `POST /containers/{name}/exec`. Alles im `Cmd` ist konstant oder validiert; das Skript nie.
     *
     * @return array<string, mixed>
     */
    public static function createBody(#[\SensitiveParameter] ExecSpec $spec): array
    {
        $env = [];
        foreach ($spec->systemEnv() as $name => $value) {
            $env[] = $name . '=' . $value;
        }
        foreach ($spec->env() as [$name, $value]) {
            $env[] = $name . '=' . $value;
        }

        return [
            'AttachStdin' => true,
            'AttachStdout' => true,
            'AttachStderr' => true,
            'DetachKeys' => self::DETACH_KEYS,
            'Tty' => false,
            'Privileged' => false,
            'User' => $spec->user ?? '',
            'WorkingDir' => $spec->workdir ?? '',
            'Env' => $env,
            'Cmd' => ['/bin/sh', '-c', self::WRAP, 'meridian-wrap', $spec->interpreter->value, $spec->nonce, (string) $spec->scriptBytes(), self::PAYLOAD_WRAP],
        ];
    }

    /**
     * Körper eines Kill-Exec (§5.5). PGID/PID als validierte Zahl, Signal aus fester Liste.
     *
     * @return array<string, mixed>
     */
    public static function killBody(ExecRef $ref, string $signal): array
    {
        if (!in_array($signal, ['TERM', 'KILL'], true) || $ref->pgid === null) {
            throw new \InvalidArgumentException('Ungültiges Signal oder keine Prozessgruppe.');
        }

        return [
            'AttachStdin' => false,
            'AttachStdout' => false,
            'AttachStderr' => false,
            'Tty' => false,
            'Privileged' => false,
            'User' => $ref->user ?? '',
            'Cmd' => ['/bin/sh', '-c', $ref->groupKill ? self::KILL_GROUP : self::KILL_PID, 'meridian-kill', $signal, (string) $ref->pgid],
        ];
    }

    /**
     * Läuft der Exec noch? null = unbekannt (Proxy nicht erreichbar); `false` auch, wenn Docker ihn nicht mehr kennt.
     */
    public static function execRunning(DockerProxyClient $proxy, string $execId): ?bool
    {
        try {
            $info = $proxy->json('GET', '/exec/' . $execId . '/json');
        } catch (ExecFailed) {
            return null;
        }
        if ($info['status'] === 404) {
            return false;
        }
        if ($info['status'] !== 200 || $info['json'] === null) {
            return null;
        }

        return ($info['json']['Running'] ?? false) === true;
    }

    /**
     * Signal an die Gruppe des Laufs über einen eigenen Exec im selben Container, als derselbe Benutzer. Vorher
     * `GET /exec/{id}/json`: läuft er nicht mehr, passiert nichts (keine wiederverwendete PID treffen).
     * true = Signal geschickt.
     */
    public static function signal(DockerProxyClient $proxy, ExecRef $ref, string $signal): bool
    {
        if ($ref->pgid === null || self::execRunning($proxy, $ref->execId) !== true) {
            return false;
        }
        try {
            $created = $proxy->json('POST', '/containers/' . $ref->container . '/exec', self::killBody($ref, $signal));
            $killId = $created['json']['Id'] ?? null;
            if ($created['status'] !== 201 || !is_string($killId) || !ExecRef::isValidExecId($killId)) {
                return false;
            }
            $started = $proxy->json('POST', '/exec/' . $killId . '/start', ['Detach' => true, 'Tty' => false]);

            return $started['status'] === 200 || $started['status'] === 204;
        } catch (ExecFailed) {
            return false;
        }
    }
}
