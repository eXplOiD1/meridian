<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Job\InvalidJobConfig;

/**
 * Der nicht geheime Teil eines Shell-Jobs: `jobs.config_json` Version 1 (docs/decisions/0004 E6).
 *
 * Enthält nie Geheimnisse: Ausführungsort (Art, Name), Interpreter, Benutzer, Arbeitsverzeichnis, Zeitlimit und
 * die Flags/Anzahl für Skript und Umgebung. Skript, Variablennamen und -werte stehen nur verschlüsselt in
 * `payload_enc` ({@see ShellPayload}).
 */
final readonly class ShellJobConfig
{
    public const VERSION = 1;

    private const SHELL_KEYS = ['env_count', 'has_env', 'has_script', 'interpreter', 'target', 'timeout_seconds', 'user', 'workdir'];

    /**
     * @param ?string $user    aufgelöster Benutzer (bei `docker` nie null: Standardbenutzer der Freigabe), bei `host` null
     * @param ?string $workdir null = Standard des Containers
     */
    public function __construct(
        public ShellTarget $target,
        public ShellInterpreter $interpreter,
        public ?string $user,
        public ?string $workdir,
        public int $timeoutSeconds,
        public bool $hasScript,
        public bool $hasEnv,
        public int $envCount,
    ) {
    }

    public function toJson(): string
    {
        return json_encode([
            'v' => self::VERSION,
            'shell' => [
                'target' => ['kind' => $this->target->kind->value, 'name' => $this->target->name],
                'interpreter' => $this->interpreter->value,
                'user' => $this->user,
                'workdir' => $this->workdir,
                'timeout_seconds' => $this->timeoutSeconds,
                'has_script' => $this->hasScript,
                'has_env' => $this->hasEnv,
                'env_count' => $this->envCount,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Liest `config_json` streng: fehlt `v` oder ist etwas unbekannt, ist der Datensatz ungültig (keine stillen
     * Standardwerte). Die Formate gelten wie beim Speichern.
     *
     * @throws InvalidJobConfig
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidJobConfig();
        }
        if (!is_array($data) || array_keys($data) !== ['v', 'shell'] && array_keys($data) !== ['shell', 'v']) {
            throw new InvalidJobConfig();
        }
        $shell = $data['shell'];
        if ($data['v'] !== self::VERSION || !is_array($shell)) {
            throw new InvalidJobConfig();
        }
        $keys = array_keys($shell);
        sort($keys);
        if ($keys !== self::SHELL_KEYS) {
            throw new InvalidJobConfig();
        }

        $target = is_array($shell['target']) ? $shell['target'] : [];
        $kind = array_keys($target) === ['kind', 'name'] && is_string($target['kind']) ? ShellTargetKind::tryFrom($target['kind']) : null;
        $name = $target['name'] ?? null;
        $interpreter = is_string($shell['interpreter']) ? ShellInterpreter::tryFrom($shell['interpreter']) : null;
        $user = $shell['user'];
        $workdir = $shell['workdir'];
        $timeout = $shell['timeout_seconds'];
        $count = $shell['env_count'];
        if ($kind === null || !is_string($name) || !$kind->isValidName($name) || $interpreter === null
            || ($user !== null && (!is_string($user) || !ShellRules::isValidUser($user)))
            || ($workdir !== null && (!is_string($workdir) || !ShellRules::isValidWorkdir($workdir)))
            || ($kind === ShellTargetKind::Host && ($user !== null || $workdir !== null))
            || ($kind === ShellTargetKind::Docker && $user === null)
            || !is_int($timeout) || $timeout < 1 || $timeout > 86400
            || !is_int($count) || $count < 0 || $count > ShellRules::MAX_ENV_VARIABLES
            || $shell['has_script'] !== true || !is_bool($shell['has_env']) || $shell['has_env'] !== ($count > 0)) {
            throw new InvalidJobConfig();
        }

        return new self(new ShellTarget($kind, $name), $interpreter, $user, $workdir, $timeout, true, $count > 0, $count);
    }
}
