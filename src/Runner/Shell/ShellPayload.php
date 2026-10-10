<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Security\Sealed;
use Meridian\Security\SecretMasker;

/**
 * Der geheime Teil eines Shell-Jobs: Skript und Umgebungsvariablen (Namen und Werte). Gespeichert nur als
 * `SecretBox::encrypt(toJson())` in `jobs.payload_enc` (docs/decisions/0004 E6).
 *
 * Format v1: `{"v": 1, "mode": "script", "script": "…", "env": [["NAME", "Wert"]]}`. Andere Versionen oder Modi
 * werden abgelehnt; ein künftiges Format wird beim Lesen umgedeutet, nie per SQL umgeschrieben.
 *
 * Die Werte liegen versiegelt ({@see Sealed}) und erscheinen in keiner Darstellung (var_dump, print_r,
 * var_export, debug_zval_dump); json_encode() liefert nur Flags, serialize() wirft. Die Web-API entschlüsselt
 * dieses Format nie; nur der Shell-Worker liest es, direkt vor dem Lauf.
 */
final class ShellPayload implements \JsonSerializable
{
    public const VERSION = 1;
    public const MODE = 'script';

    /** Ab dieser Länge registriert registerIn() einzelne Zeilen und Wörter (kürzere würden zu viel verdecken). */
    private const MIN_FRAGMENT_LENGTH = 8;
    private const MIN_TOKEN_LENGTH = 16;

    /** @var Sealed<string> */
    private readonly Sealed $script;

    /** @var Sealed<list<array{0: string, 1: string}>> */
    private readonly Sealed $env;

    private readonly int $envCount;

    /**
     * @param list<array{0: string, 1: string}> $env Name und Wert, in der Reihenfolge der Eingabe
     *
     * @throws InvalidShellPayload mit Feldpfad und fester Meldung
     */
    public function __construct(#[\SensitiveParameter] string $script, #[\SensitiveParameter] array $env = [])
    {
        $problem = ShellRules::scriptProblem($script);
        if ($problem !== null) {
            throw new InvalidShellPayload('script.source', $problem);
        }
        self::checkEnv($env);
        $this->script = new Sealed($script);
        $this->env = new Sealed($env);
        $this->envCount = count($env);
    }

    /**
     * @throws InvalidShellPayload nur mit fester Meldung, nie mit Inhalt
     */
    public static function fromJson(#[\SensitiveParameter] string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidShellPayload::unreadable();
        }
        if (!is_array($data) || !array_key_exists('v', $data)) {
            throw InvalidShellPayload::unreadable();
        }
        if ($data['v'] !== self::VERSION || ($data['mode'] ?? null) !== self::MODE) {
            throw InvalidShellPayload::unknownVersion();
        }
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['env', 'mode', 'script', 'v'] || !is_string($data['script']) || !is_array($data['env']) || !array_is_list($data['env'])) {
            throw InvalidShellPayload::unreadable();
        }
        $env = [];
        foreach ($data['env'] as $pair) {
            if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2 || !is_string($pair[0]) || !is_string($pair[1])) {
                throw InvalidShellPayload::unreadable();
            }
            $env[] = [$pair[0], $pair[1]];
        }

        try {
            return new self($data['script'], $env);
        } catch (InvalidShellPayload) {
            throw InvalidShellPayload::unreadable();
        }
    }

    public function toJson(): string
    {
        return json_encode(
            ['v' => self::VERSION, 'mode' => self::MODE, 'script' => $this->script->open(), 'env' => $this->env->open()],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public function script(): string
    {
        return $this->script->open();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function env(): array
    {
        return $this->env->open();
    }

    public function envCount(): int
    {
        return $this->envCount;
    }

    public function hasEnv(): bool
    {
        return $this->envCount > 0;
    }

    /**
     * Registriert jeden geheimen Bestandteil im Masker des Laufs (ADR 0004 E8). Sofort nach dem Entschlüsseln
     * aufrufen, vor jeder Ausgabe. Lieber zu viel als zu wenig: der Masker ignoriert selbst Werte unter vier
     * Zeichen.
     *
     * - jeder Umgebungswert ganz, bei mehrzeiligen Werten (Schlüsseldateien) zusätzlich jede Zeile;
     * - das Skript ganz und zeilenweise (`set -x` gibt Zeilen aus) sowie lange Wörter mit Buchstaben und Ziffern
     *   (Zugangsdaten als Literal im Skript).
     */
    public function registerIn(SecretMasker $masker): void
    {
        foreach ($this->env->open() as [, $value]) {
            $masker->remember($value);
            if (str_contains($value, "\n")) {
                self::rememberLines($masker, $value);
            }
        }

        $script = $this->script->open();
        $masker->remember($script);
        self::rememberLines($masker, $script);
        $words = preg_split('/[\s\'"`=:;,()<>|&$]+/', $script);
        foreach ($words === false ? [] : $words as $word) {
            if (strlen($word) >= self::MIN_TOKEN_LENGTH && preg_match('/[0-9]/', $word) === 1 && preg_match('/[A-Za-z]/', $word) === 1) {
                $masker->remember($word);
            }
        }
    }

    /**
     * @return array<string, int|bool|string>
     */
    public function __debugInfo(): array
    {
        return ['v' => self::VERSION, 'has_script' => true, 'env_count' => $this->envCount];
    }

    /**
     * Nur Flags; den gespeicherten Inhalt liefert ausschließlich toJson().
     *
     * @return array<string, int|bool|string>
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
        throw new \LogicException('Ein Skript mit Geheimnissen wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Ein Skript mit Geheimnissen wird nicht deserialisiert.');
    }

    /**
     * @param array<mixed> $env
     */
    private static function checkEnv(#[\SensitiveParameter] array $env): void
    {
        if (count($env) > ShellRules::MAX_ENV_VARIABLES) {
            throw new InvalidShellPayload('script.env', 'Zu viele Umgebungsvariablen (höchstens 50).');
        }
        $seen = [];
        $total = 0;
        foreach (array_values($env) as $i => $pair) {
            $field = 'script.env[' . $i . ']';
            if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_string($pair[0]) || !is_string($pair[1])) {
                throw new InvalidShellPayload($field, 'Eine Umgebungsvariable ist ein Objekt mit name und value (beide Text).');
            }
            [$name, $value] = [$pair[0], $pair[1]];
            $problem = ShellRules::envNameProblem($name);
            if ($problem !== null) {
                throw new InvalidShellPayload($field . '.name', $problem);
            }
            if (isset($seen[$name])) {
                throw new InvalidShellPayload($field . '.name', 'Dieser Name kommt mehrfach vor. Jeder Name darf nur einmal angegeben werden.');
            }
            $seen[$name] = true;
            $problem = ShellRules::envValueProblem($value);
            if ($problem !== null) {
                throw new InvalidShellPayload($field . '.value', $problem);
            }
            $total += strlen($name) + strlen($value);
        }
        if ($total > ShellRules::MAX_ENV_TOTAL_BYTES) {
            throw new InvalidShellPayload('script.env', 'Die Umgebungsvariablen sind zusammen zu groß (höchstens 32 KiB).');
        }
    }

    private static function rememberLines(SecretMasker $masker, #[\SensitiveParameter] string $text): void
    {
        $lines = preg_split('/\R/u', $text);
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            if (strlen($line) >= self::MIN_FRAGMENT_LENGTH) {
                $masker->remember($line);
            }
        }
    }
}
