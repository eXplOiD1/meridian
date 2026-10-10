<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Runner\Shell\InvalidShellPayload;
use Meridian\Runner\Shell\ShellInterpreter;
use Meridian\Runner\Shell\ShellJobConfig;
use Meridian\Runner\Shell\ShellPayload;
use Meridian\Runner\Shell\ShellRules;
use Meridian\Runner\Shell\ShellTarget;
use Meridian\Runner\Shell\ShellTargetKind;
use Meridian\Runner\Shell\ShellTargetPolicy;
use Meridian\Runner\Shell\ShellTargetRefusal;
use Meridian\Settings\Settings;

/**
 * Der Shell-Teil der Job-Validierung (docs/decisions/0004 §4.3): Ausführungsort, Interpreter, Benutzer,
 * Arbeitsverzeichnis, Zeitlimit und Skript mit Umgebung. Allowlist, ablehnen statt zurechtschneiden; die Meldungen
 * sind feste Texte und nennen nie einen Wert (Skript und Umgebung sind Geheimnisse).
 *
 * Beim Ändern gelten für fehlende `shell`-Felder die gespeicherten Werte. `script` ersetzt Quelltext **und**
 * Umgebung immer zusammen; fehlt es, bleibt die gespeicherte `payload_enc` unverändert und wird nie gelesen oder
 * entschlüsselt. Der Ausführungsort wird bei jedem Speichern gegen die Freigaben der Zielkategorie geprüft.
 */
final class ShellJobValidator
{
    public const SHELL_KEYS = ['target', 'interpreter', 'user', 'workdir', 'timeout_seconds'];
    private const SCRIPT_KEYS = ['source', 'env'];

    private const MSG_TARGET_FORMAT = 'Ausführungsort: Objekt mit kind („docker“ oder „host“) und name.';
    private const MSG_ROOT = 'Root ist für diesen Ausführungsort nicht ausdrücklich freigegeben. Ein Admin kann „root“ in der Freigabe eintragen (Einstellungen → Ausführungsorte).';

    public function __construct(
        private readonly Settings $settings,
        private readonly ?ShellTargetPolicy $targets,
    ) {
    }

    /**
     * @param array<mixed>          $input
     * @param array<string, string> $errors
     *
     * @return array{0: ShellJobConfig|null, 1: ShellPayload|null} Payload null = bleibt unverändert (nur beim Ändern)
     */
    public function validate(#[\SensitiveParameter] array $input, ?JobRecord $existing, ?int $categoryId, bool $categoryValid, array &$errors): array
    {
        $old = $existing?->shell;
        $shell = array_key_exists('shell', $input) ? self::asObject($input['shell']) : [];
        if ($shell === null) {
            $errors['shell'] = 'Muss ein Objekt sein.';
            $shell = [];
        } elseif (array_diff(array_map(strval(...), array_keys($shell)), self::SHELL_KEYS) !== []) {
            $errors['shell'] = 'Unbekannte Felder in „shell“. Erlaubt sind: ' . implode(', ', self::SHELL_KEYS) . '.';
        }

        $target = $this->target($shell, $old, $errors);
        $interpreter = $this->interpreter($shell, $old, $errors);
        $user = $this->user($shell, $old, $target, $errors);
        $workdir = $this->workdir($shell, $old, $target, $errors);
        $maxTimeout = $this->settings->shellMaxTimeoutSeconds();
        $timeout = $this->timeout($shell, $old, $maxTimeout, $errors);

        $resolved = $user;
        if ($target !== null && !isset($errors['shell.target']) && !isset($errors['shell.user']) && $categoryValid) {
            $refusal = $this->targets === null ? ShellTargetRefusal::TargetNotAllowed : $this->targets->check($target, $user, $categoryId);
            if ($refusal === ShellTargetRefusal::TargetNotAllowed) {
                $errors['shell.target'] = $refusal->message();
            } elseif ($refusal === ShellTargetRefusal::UserNotAllowed) {
                $errors['shell.user'] = $user !== null && ShellRules::isRootUser($user) ? self::MSG_ROOT : $refusal->message();
            } elseif ($this->targets !== null) {
                $resolved = $this->targets->resolveUser($target, $user, $categoryId);
            }
        }

        $payload = $this->script($input, $existing, $errors);

        if ($errors !== [] || $target === null || $interpreter === null || $timeout === null) {
            return [null, $payload];
        }
        $hasEnv = $payload !== null ? $payload->hasEnv() : ($old !== null && $old->hasEnv);
        $envCount = $payload !== null ? $payload->envCount() : ($old !== null ? $old->envCount : 0);

        return [new ShellJobConfig($target, $interpreter, $resolved, $workdir, $timeout, true, $hasEnv, $envCount), $payload];
    }

    /**
     * @param array<mixed>          $shell
     * @param array<string, string> $errors
     */
    private function target(array $shell, ?ShellJobConfig $old, array &$errors): ?ShellTarget
    {
        if (!array_key_exists('target', $shell)) {
            if ($old === null) {
                $errors['shell.target'] = 'Pflichtfeld: den Ausführungsort angeben (kind und name).';
            }

            return $old?->target;
        }
        $raw = self::asObject($shell['target']);
        if ($raw === null || array_diff(array_map(strval(...), array_keys($raw)), ['kind', 'name']) !== []) {
            $errors['shell.target'] = self::MSG_TARGET_FORMAT;

            return null;
        }
        $kind = isset($raw['kind']) && is_string($raw['kind']) ? ShellTargetKind::tryFrom($raw['kind']) : null;
        if ($kind === null) {
            $errors['shell.target.kind'] = 'Art: erlaubt sind „docker“ und „host“.';

            return null;
        }
        $name = $raw['name'] ?? null;
        if (!is_string($name) || !$kind->isValidName($name)) {
            // Format falsch: sagt nichts darüber, ob es den Ort gibt.
            $errors['shell.target.name'] = $kind === ShellTargetKind::Docker
                ? 'Container-Name: 1–128 Zeichen aus Buchstaben, Ziffern, Unterstrich und Bindestrich (kein Punkt).'
                : 'Profilname: 1–32 Kleinbuchstaben, Ziffern oder Bindestriche, mit einem Buchstaben beginnend.';

            return null;
        }

        return new ShellTarget($kind, $name);
    }

    /**
     * @param array<mixed>          $shell
     * @param array<string, string> $errors
     */
    private function interpreter(array $shell, ?ShellJobConfig $old, array &$errors): ?ShellInterpreter
    {
        if (!array_key_exists('interpreter', $shell)) {
            return $old === null ? ShellInterpreter::Sh : $old->interpreter;
        }
        $interpreter = is_string($shell['interpreter']) ? ShellInterpreter::tryFrom($shell['interpreter']) : null;
        if ($interpreter === null) {
            $errors['shell.interpreter'] = 'Interpreter: erlaubt sind „sh“ und „bash“.';
        }

        return $interpreter;
    }

    /**
     * `null` im Feld = Standardbenutzer der Freigabe (wird beim Speichern zum Namen aufgelöst). Bei `host` immer null.
     *
     * @param array<mixed>          $shell
     * @param array<string, string> $errors
     */
    private function user(array $shell, ?ShellJobConfig $old, ?ShellTarget $target, array &$errors): ?string
    {
        if (!array_key_exists('user', $shell)) {
            return $target?->kind === ShellTargetKind::Docker ? $old?->user : null;
        }
        $user = $shell['user'];
        if ($user === null) {
            return null;
        }
        if (!is_string($user) || !ShellRules::isValidUser($user)) {
            $errors['shell.user'] = 'Benutzer: Name in Kleinbuchstaben (z. B. www-data) oder numerische ID (z. B. 1001 oder 1001:1001), oder null für den Standardbenutzer der Freigabe.';

            return null;
        }
        if ($target?->kind === ShellTargetKind::Host) {
            $errors['shell.user'] = 'Bei Host-Ausführungsorten ist der Benutzer fest (meridian-run). „user“ null lassen.';

            return null;
        }

        return $user;
    }

    /**
     * @param array<mixed>          $shell
     * @param array<string, string> $errors
     */
    private function workdir(array $shell, ?ShellJobConfig $old, ?ShellTarget $target, array &$errors): ?string
    {
        $isHost = $target?->kind === ShellTargetKind::Host;
        if (!array_key_exists('workdir', $shell)) {
            return $isHost ? null : $old?->workdir;
        }
        $workdir = $shell['workdir'];
        if ($workdir === null) {
            return null;
        }
        if (!is_string($workdir) || !ShellRules::isValidWorkdir($workdir)) {
            $errors['shell.workdir'] = 'Arbeitsverzeichnis: absoluter Pfad aus Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich (höchstens 255 Byte, 32 Ebenen, ohne „.“ und „..“), oder null für den Standard des Containers.';

            return null;
        }
        if ($isHost) {
            $errors['shell.workdir'] = 'Bei Host-Ausführungsorten ist das Arbeitsverzeichnis fest. „workdir“ null lassen.';

            return null;
        }

        return $workdir;
    }

    /**
     * @param array<mixed>          $shell
     * @param array<string, string> $errors
     */
    private function timeout(array $shell, ?ShellJobConfig $old, int $max, array &$errors): ?int
    {
        $value = array_key_exists('timeout_seconds', $shell) ? $shell['timeout_seconds'] : ($old === null ? min(ShellRules::DEFAULT_TIMEOUT_SECONDS, $max) : $old->timeoutSeconds);
        if (!is_int($value) || $value < 1 || $value > $max) {
            $errors['shell.timeout_seconds'] = 'Zeitlimit höchstens ' . $max . ' s (Einstellung des Administrators), mindestens 1 s.';

            return null;
        }

        return $value;
    }

    /**
     * Skript und Umgebung als Ganzes. null = bleibt unverändert (nur beim Ändern ohne `script`).
     *
     * @param array<mixed>          $input
     * @param array<string, string> $errors
     */
    private function script(#[\SensitiveParameter] array $input, ?JobRecord $existing, array &$errors): ?ShellPayload
    {
        if (!array_key_exists('script', $input)) {
            if ($existing === null) {
                $errors['script'] = 'Pflichtfeld: das Skript (source, optional env) angeben.';
            } elseif ($existing->shell === null) {
                $errors['script'] = 'Die gespeicherte Konfiguration ist unlesbar. Bitte Ausführungsort und Skript neu eingeben.';
            }

            return null;
        }
        $script = self::asObject($input['script']);
        if ($script === null || array_diff(array_map(strval(...), array_keys($script)), self::SCRIPT_KEYS) !== []) {
            $errors['script'] = 'Muss ein Objekt mit source und env sein (Erlaubt sind: ' . implode(', ', self::SCRIPT_KEYS) . ').';

            return null;
        }
        $source = $script['source'] ?? null;
        if (!is_string($source)) {
            $errors['script.source'] = 'Pflichtfeld: den Quelltext des Skripts als Text angeben.';

            return null;
        }
        $env = $this->env($script['env'] ?? [], $errors);
        if ($env === null) {
            return null;
        }

        try {
            return new ShellPayload($source, $env);
        } catch (InvalidShellPayload $e) {
            $errors[$e->field] = $e->getMessage();

            return null;
        }
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private function env(#[\SensitiveParameter] mixed $value, array &$errors): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors['script.env'] = 'Umgebung: Liste von Objekten mit name und value.';

            return null;
        }
        $env = [];
        foreach ($value as $i => $item) {
            if (!is_array($item) || array_keys($item) !== ['name', 'value'] && array_keys($item) !== ['value', 'name']
                || !is_string($item['name']) || !is_string($item['value'])) {
                $errors['script.env[' . $i . ']'] = 'Eine Umgebungsvariable ist ein Objekt mit name und value (beide Text).';

                return null;
            }
            $env[] = [$item['name'], $item['value']];
        }

        return $env;
    }

    /**
     * @return array<mixed>|null
     */
    private static function asObject(#[\SensitiveParameter] mixed $value): ?array
    {
        return is_array($value) && ($value === [] || !array_is_list($value)) ? $value : null;
    }
}
