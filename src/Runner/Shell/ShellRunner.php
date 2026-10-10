<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Job\InvalidJobConfig;
use Meridian\Runner\Heartbeat;
use Meridian\Runner\JobType;
use Meridian\Runner\LiveLog;
use Meridian\Runner\LiveStream;
use Meridian\Runner\Runner;
use Meridian\Runner\RunRequest;
use Meridian\Runner\RunResult;
use Meridian\Security\SecretBox;
use Meridian\Security\SecretMasker;
use Meridian\Settings\Settings;

/**
 * Führt einen Shell-Job aus (docs/decisions/0004 §5.3).
 *
 *  1. Job frisch laden (Typ, **gespeicherte** Kategorie), `ShellJobConfig` streng lesen, Zeitlimit
 *     `min(Job, shell.max_timeout_seconds)` frisch je Lauf (Notiz bei Kappung).
 *  2. {@see ShellTargetPolicy::check()} mit der Kategorie aus der Datenbank — bei **jedem** Lauf.
 *  3. `beat()`, Payload entschlüsseln, **sofort** im Masker des Laufs registrieren.
 *  4. {@see Executor::start()} für die Art des Ausführungsorts; `exec_ref` über {@see ExecRefSink}.
 *  5. Lesen in Durchgängen ≤ 1 s (auch bei stillem Prozess), danach jeweils `beat()`. Rohbytes zählen →
 *     {@see StreamMasker} je Strom → {@see OutputCollector} und {@see LiveLog}. `beat() === false` → SIGTERM an die
 *     Gruppe, bis {@see self::GRACE_SECONDS} weiter leeren (nicht speichern), dann SIGKILL → `aborted`. Zeitlimit →
 *     ebenso → `timeout`. Mehr als {@see self::MAX_RAW_BYTES} Rohbytes → ebenso → `failed`, nicht wiederholbar.
 *  6. Exit 0 → `ok`; sonst `failed` (wiederholbar); 126/127 ohne Ausgabe → Interpreter fehlt (nicht wiederholbar).
 *
 * Notizen sind feste Texte, nie Meldungen aus Docker oder dem System. Die Ausgabe ist maskiert; der Worker maskiert
 * sie erneut und kürzt sicher.
 */
final class ShellRunner implements Runner
{
    public const MAX_RAW_BYTES = 67108864;
    public const GRACE_SECONDS = 10.0;
    /** Längste Wartezeit eines Lesedurchgangs: so oft schlägt der Herzschlag mindestens. */
    public const MAX_READ_WAIT = 1.0;
    /** Nach SIGKILL so lange auf das Ende warten, dann aufgeben (close() räumt). */
    private const KILL_WAIT_SECONDS = 5.0;

    public const NOTE_NOT_SHELL = 'Fehlgeschlagen: Der Job ist nicht mehr vorhanden oder kein Shell-Job.';
    public const NOTE_ABORTED = 'Abgebrochen: Der Lauf wurde beendet.';
    public const NOTE_FLOOD = 'Ausgabe über 64 MiB: Prozess beendet.';
    public const NOTE_NO_INTERPRETER = 'Interpreter im Container nicht gefunden.';
    /** Ort `host` ohne Host-Agent (bis S11): sofort beenden, nie still warten oder wiederholen. */
    public const NOTE_HOST_UNAVAILABLE = 'Host-Ausführung ist in dieser Version noch nicht verfügbar (kommt mit der systemd-Unterstützung). Ausführungsort vom Typ Container verwenden.';
    public const NOTE_NO_DOCKER = 'Docker-Proxy nicht eingerichtet: MERIDIAN_DOCKER_PROXY auf unix:///… setzen (docs/decisions/0004 E10).';

    /**
     * @param array<string, Executor> $executors je {@see ShellTargetKind} (Wert); im Worker nur `docker`
     *                                           ({@see DockerExecExecutor}), nie {@see LocalProcessExecutor}
     */
    public function __construct(
        private readonly ShellJobSource $jobs,
        private readonly SecretBox $box,
        private readonly ShellRunSettings $settings,
        private readonly ShellTargetPolicy $policy,
        private readonly array $executors,
        private readonly ExecRefSink $refs = new NullExecRefSink(),
        private readonly float $graceSeconds = self::GRACE_SECONDS,
    ) {
    }

    #[\Override]
    public function run(RunRequest $request, Heartbeat $heartbeat, SecretMasker $masker, LiveLog $live): RunResult
    {
        $job = $this->jobs->load($request->jobId);
        if ($job === null || $job->type !== JobType::Shell->value) {
            return RunResult::failed('', self::NOTE_NOT_SHELL, retryable: false);
        }
        try {
            $config = ShellJobConfig::fromJson($job->configJson);
        } catch (InvalidJobConfig $e) {
            return RunResult::failed('', $e->getMessage(), retryable: false);
        }

        $notes = [];
        $maximum = max(Settings::MIN_TIMEOUT_SECONDS, min(Settings::SHELL_MAX_TIMEOUT_LIMIT_SECONDS, $this->settings->maxTimeoutSeconds()));
        $timeout = $config->timeoutSeconds;
        if ($timeout > $maximum) {
            $timeout = $maximum;
            $notes[] = 'Zeitlimit auf das Maximum von ' . $maximum . ' s begrenzt.';
        }

        // Bei jedem Lauf frisch, mit der Kategorie aus der Datenbank (E4).
        $refusal = $this->policy->check($config->target, $config->user, $job->categoryId);
        if ($refusal !== null) {
            return RunResult::failed('', $refusal->runNote(), retryable: false);
        }
        $user = $this->policy->resolveUser($config->target, $config->user, $job->categoryId);

        $executor = $this->executors[$config->target->kind->value] ?? null;
        if ($executor === null) {
            return $config->target->kind === ShellTargetKind::Docker
                ? RunResult::failed('', self::NOTE_NO_DOCKER)
                : RunResult::failed('', self::NOTE_HOST_UNAVAILABLE, retryable: false);
        }

        if (!$heartbeat->beat()) {
            // Abgebrochen, bevor etwas gestartet wurde: nie wiederholen (der Worker setzt die Notiz nach dem Grund).
            return RunResult::aborted('', self::NOTE_ABORTED);
        }
        try {
            $payload = ShellPayload::fromJson($this->box->decrypt($job->payloadEnc));
        } catch (InvalidShellPayload $e) {
            return RunResult::failed('', $e->getMessage(), retryable: false);
        } catch (\Throwable) {
            // Schlüssel falsch, Wert beschädigt: feste Meldung, nie die der Ausnahme.
            return RunResult::failed('', InvalidShellPayload::unreadable()->getMessage(), retryable: false);
        }
        // Sofort, vor jeder Ausgabe (§5.3 Schritt 3).
        $payload->registerIn($masker);

        try {
            $spec = new ExecSpec(
                $request->runId,
                $request->jobId,
                $request->trigger->value,
                $config->target,
                $config->interpreter,
                $user,
                $config->workdir,
                $job->timezone,
                ExecSpec::newNonce(),
                $payload->script(),
                $payload->env(),
            );
            $execution = $executor->start($spec);
        } catch (ExecFailed $e) {
            return RunResult::failed('', $e->getMessage(), retryable: $e->retryable);
        }
        unset($spec, $payload);

        return $this->supervise($request->runId, $execution, $timeout, $notes, $heartbeat, $masker, $live);
    }

    /**
     * @param list<string> $notes
     */
    private function supervise(int $runId, Execution $execution, int $timeout, array $notes, Heartbeat $heartbeat, SecretMasker $masker, LiveLog $live): RunResult
    {
        $collector = new OutputCollector();
        $maskers = ['out' => new StreamMasker($masker), 'err' => new StreamMasker($masker)];
        $deadline = microtime(true) + (float) $timeout;
        /** @var 'abort'|'timeout'|'flood'|null $stop */
        $stop = null;
        $termAt = 0.0;
        $killAt = null;
        $alive = true;
        $failure = null;

        try {
            $lastRef = $this->recordRef($runId, $execution, null);
            while (!$execution->finished()) {
                $now = microtime(true);
                if ($stop === null) {
                    $wait = $deadline - $now;
                } elseif ($killAt === null) {
                    $wait = $termAt + $this->graceSeconds - $now;
                } else {
                    $wait = $killAt + self::KILL_WAIT_SECONDS - $now;
                }
                $block = $execution->read(max(0.01, min(self::MAX_READ_WAIT, $wait)));
                $lastRef = $this->recordRef($runId, $execution, $lastRef);

                if ($block !== null) {
                    $collector->countRaw($block->length());
                    // Nach Abbruch oder Ausgabeflut nur noch leeren, nicht speichern.
                    if ($stop === null || $stop === 'timeout') {
                        $key = $block->stream === LiveStream::Err ? 'err' : 'out';
                        $this->emit($block->stream, $maskers[$key]->feed($block->bytes()), $collector, $live);
                    }
                    if ($stop === null && $collector->rawBytes() > self::MAX_RAW_BYTES) {
                        $stop = 'flood';
                        $termAt = microtime(true);
                        $execution->terminate();
                    }
                }

                if ($alive && !$heartbeat->beat()) {
                    $alive = false;
                    if ($stop === null) {
                        $stop = 'abort';
                        $termAt = microtime(true);
                        $execution->terminate();
                    }
                }

                $now = microtime(true);
                if ($stop === null && $now >= $deadline) {
                    $stop = 'timeout';
                    $termAt = $now;
                    $execution->terminate();
                } elseif ($stop !== null && $killAt === null && $now >= $termAt + $this->graceSeconds) {
                    $killAt = $now;
                    $execution->kill();
                } elseif ($killAt !== null && $now >= $killAt + self::KILL_WAIT_SECONDS) {
                    break;
                }
            }
        } catch (ExecFailed $e) {
            $failure = $e;
            try {
                $execution->terminate();
            } catch (\Throwable) {
                // close() versucht es erneut.
            }
        } finally {
            $finished = false;
            try {
                $finished = $execution->finished();
                $notes = [...$notes, ...$execution->notes()];
            } catch (\Throwable) {
                // Zustand unbekannt: exec_ref bleibt stehen, das Aufräumen prüft über die Exec-ID.
            }
            $execution->close();
            if ($finished) {
                $this->refs->clear($runId);
            }
        }

        if ($stop === null || $stop === 'timeout') {
            foreach (['out' => LiveStream::Out, 'err' => LiveStream::Err] as $key => $stream) {
                $this->emit($stream, $maskers[$key]->flush(), $collector, $live);
            }
        }
        if ($maskers['out']->hidLongLine() || $maskers['err']->hidLongLine()) {
            $notes[] = StreamMasker::NOTE_LONG_LINE;
        }
        $live->flush();

        return $this->result($execution, $stop, $failure, $timeout, $collector, $notes)->withOutputBytes($collector->rawBytes());
    }

    /**
     * @param 'abort'|'timeout'|'flood'|null $stop
     * @param list<string>                   $notes
     */
    private function result(Execution $execution, ?string $stop, ?ExecFailed $failure, int $timeout, OutputCollector $collector, array $notes): RunResult
    {
        $output = $collector->text();
        $note = static fn (string ...$more): string => implode(' ', [...$more, ...$notes]);

        if ($stop === 'abort') {
            // Der Worker ersetzt das je nach Grund durch „Abgebrochen durch Benutzer“ bzw. Worker beendet.
            return RunResult::aborted($output, $note(self::NOTE_ABORTED));
        }
        if ($stop === 'timeout') {
            return RunResult::timeout($output, $note('Zeitlimit von ' . $timeout . ' s überschritten, Prozess beendet.'));
        }
        if ($stop === 'flood') {
            return RunResult::failed($output, $note(self::NOTE_FLOOD), retryable: false);
        }
        if ($failure !== null) {
            return RunResult::failed($output, $note($failure->getMessage()), retryable: $failure->retryable);
        }

        $signal = $execution->termSignal();
        if ($signal !== null) {
            return RunResult::failed($output, $note('Beendet durch Signal ' . $signal . '.'));
        }
        $code = $execution->exitCode();
        if ($code === 0) {
            return RunResult::ok($output, 0, null, $notes === [] ? null : $note());
        }
        if ($code === null) {
            return RunResult::failed($output, $note(ExecFailed::CONNECTION_LOST));
        }
        if (($code === 126 || $code === 127) && $collector->rawBytes() === 0) {
            return RunResult::failed($output, $note(self::NOTE_NO_INTERPRETER), $code, retryable: false);
        }

        return RunResult::failed($output, $note('Beendet mit Exit-Code ' . $code . '.'), $code);
    }

    private function emit(LiveStream $stream, #[\SensitiveParameter] string $masked, OutputCollector $collector, LiveLog $live): void
    {
        if ($masked === '') {
            return;
        }
        $collector->append($stream, $masked);
        $live->append($stream, $masked);
    }

    private function recordRef(int $runId, Execution $execution, ?string $last): ?string
    {
        $ref = $execution->ref();
        if ($ref === null) {
            return $last;
        }
        $json = $ref->toJson();
        if ($json !== $last) {
            $this->refs->record($runId, $ref);
        }

        return $json;
    }
}
