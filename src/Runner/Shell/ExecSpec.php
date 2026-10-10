<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Security\Sealed;

/**
 * Auftrag an einen {@see Executor} (docs/decisions/0004 §5.1): Ort, Interpreter, Benutzer, Arbeitsverzeichnis,
 * Nonce und — versiegelt — Skript und Umgebungsvariablen des Jobs. Alles außer Skript und Umgebung ist validiert und
 * kein Geheimnis. Keine Darstellung zeigt Skript oder Umgebung; serialize() wirft.
 */
final class ExecSpec implements \JsonSerializable
{
    private const NONCE = '/^[0-9a-f]{32}$/D';

    /** @var Sealed<string> */
    private readonly Sealed $script;

    /** @var Sealed<list<array{0: string, 1: string}>> */
    private readonly Sealed $env;

    private readonly int $scriptBytes;

    /**
     * @param list<array{0: string, 1: string}> $env Umgebungsvariablen des Jobs (bereits nach E12 geprüft)
     */
    public function __construct(
        public readonly int $runId,
        public readonly int $jobId,
        public readonly string $trigger,
        public readonly ShellTarget $target,
        public readonly ShellInterpreter $interpreter,
        public readonly ?string $user,
        public readonly ?string $workdir,
        public readonly string $timezone,
        public readonly string $nonce,
        #[\SensitiveParameter] string $script,
        #[\SensitiveParameter] array $env,
    ) {
        if (preg_match(self::NONCE, $nonce) !== 1 || !$target->kind->isValidName($target->name)
            || ($user !== null && !ShellRules::isValidUser($user)) || ($workdir !== null && !ShellRules::isValidWorkdir($workdir))
            || preg_match('/^[a-z]{1,16}$/D', $trigger) !== 1 || $runId < 1 || $jobId < 1) {
            throw ExecFailed::invalidSpec();
        }
        foreach ($env as [$name]) {
            if (ShellRules::envNameProblem($name) !== null) {
                throw ExecFailed::invalidSpec();
            }
        }
        $this->script = new Sealed($script);
        $this->env = new Sealed($env);
        $this->scriptBytes = strlen($script);
    }

    public static function newNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function script(): string
    {
        return $this->script->open();
    }

    public function scriptBytes(): int
    {
        return $this->scriptBytes;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function env(): array
    {
        return $this->env->open();
    }

    /**
     * Was Meridian selbst setzt (E12): Job, Lauf, Auslöser, Zeitzone des Jobs. Keine Geheimnisse.
     *
     * @return array<string, string>
     */
    public function systemEnv(): array
    {
        $tz = in_array($this->timezone, \DateTimeZone::listIdentifiers(), true) ? $this->timezone : 'UTC';

        return [
            'MERIDIAN_JOB_ID' => (string) $this->jobId,
            'MERIDIAN_RUN_ID' => (string) $this->runId,
            'MERIDIAN_TRIGGER' => $this->trigger,
            'TZ' => $tz,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'run_id' => $this->runId,
            'target' => $this->target->describe(),
            'interpreter' => $this->interpreter->value,
            'script_bytes' => $this->scriptBytes,
            'env_count' => count($this->env->open()),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Ein Ausführungsauftrag wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Ein Ausführungsauftrag wird nicht deserialisiert.');
    }
}
