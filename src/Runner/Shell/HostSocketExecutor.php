<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Runner\Shell\Agent\Frames;
use Meridian\Runner\Shell\Agent\ProtocolError;

/**
 * Host-Ausführung im Worker (ADR 0004 E3/§5.6, ADR 0014): verbindet sich mit dem Agent-Socket des freigegebenen Profils
 * (`host <profil>`, Pfad nur aus der Allowlist {@see HostSockets}), schickt Auftrag und Skript gerahmt und gibt eine
 * {@see HostExecution} zurück. Der Worker startet nie selbst einen Prozess: den Befehl führt der Agent als eigener Benutzer
 * (`meridian-run`) ohne Zugriff auf Schlüssel und Datenbank aus.
 */
final class HostSocketExecutor implements Executor
{
    public function __construct(
        private readonly HostSockets $sockets,
        private readonly float $connectSeconds = 2.0,
    ) {
    }

    #[\Override]
    public function start(#[\SensitiveParameter] ExecSpec $spec): Execution
    {
        if ($spec->target->kind !== ShellTargetKind::Host) {
            throw ExecFailed::invalidSpec();
        }
        $path = $this->sockets->pathFor($spec->target->name);
        if ($path === null) {
            throw new ExecFailed(ExecFailed::HOST_NOT_SET_UP, false);
        }
        $stream = @stream_socket_client('unix://' . $path, $errorCode, $errorText, $this->connectSeconds);
        if (!is_resource($stream)) {
            throw new ExecFailed(ExecFailed::HOST_NOT_SET_UP, true);
        }
        try {
            $request = json_encode([
                'v' => 1,
                'run_id' => $spec->runId,
                'job_id' => $spec->jobId,
                'trigger' => $spec->trigger,
                'interpreter' => $spec->interpreter->value,
                'env' => $spec->env(),
                'nonce' => $spec->nonce,
                'script_bytes' => $spec->scriptBytes(),
                'timezone' => $spec->timezone,
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $ok = Frames::write($stream, Frames::encode('S', $request));
            $script = $spec->script();
            for ($offset = 0; $ok && $offset < strlen($script); $offset += Frames::CHUNK) {
                $ok = Frames::write($stream, Frames::encode('I', substr($script, $offset, Frames::CHUNK)));
            }
        } catch (ProtocolError | \JsonException) {
            fclose($stream);
            throw ExecFailed::invalidSpec();
        }
        if (!$ok) {
            fclose($stream);
            throw ExecFailed::hostLost();
        }

        return new HostExecution($stream, $spec->nonce);
    }
}
