<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Die Formate für Benutzer, Arbeitsverzeichnis und Umgebungsvariablen eines Shell-Jobs (docs/decisions/0004 E12).
 * Eine Stelle für Speichern (API, CLI) und Lesen (Runner): ablehnen, nie zurechtschneiden.
 */
final class ShellRules
{
    public const MAX_SCRIPT_BYTES = 262144;
    public const MAX_ENV_VARIABLES = 50;
    public const MAX_ENV_VALUE_BYTES = 4096;
    public const MAX_ENV_TOTAL_BYTES = 32768;
    public const MAX_USERS_PER_TARGET = 10;
    public const MAX_WORKDIR_BYTES = 255;
    public const MAX_WORKDIR_SEGMENTS = 32;
    public const DEFAULT_TIMEOUT_SECONDS = 300;

    private const USER_NAME = '/^[a-z_][a-z0-9_-]{0,31}$/D';
    private const USER_ID = '/^[0-9]{1,10}(:[0-9]{1,10})?$/D';
    private const ENV_NAME = '/^[A-Z_][A-Z0-9_]{0,63}$/D';

    /** Diese Namen setzt Meridian selbst oder sie verändern die Shell (Groß/klein egal). */
    private const FORBIDDEN_ENV = ['BASH_ENV', 'ENV', 'SHELLOPTS', 'BASHOPTS', 'IFS', 'PS4', 'PROMPT_COMMAND', 'PATH', 'HOME', 'TZ'];

    private function __construct()
    {
    }

    public static function isValidUser(string $user): bool
    {
        return preg_match(self::USER_NAME, $user) === 1 || preg_match(self::USER_ID, $user) === 1;
    }

    /** `root`, UID 0 oder `root:…`/`0:…`: nur mit ausdrücklicher Freigabe im Ausführungsort (O10). */
    public static function isRootUser(string $user): bool
    {
        $name = explode(':', $user, 2)[0];

        return $name === 'root' || preg_match('/^0+$/D', $name) === 1;
    }

    /**
     * Absoluter Pfad ohne `.`/`..`-Segmente, ohne doppelte Schrägstriche; ein abschließender Schrägstrich ist erlaubt.
     */
    public static function isValidWorkdir(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || strlen($path) > self::MAX_WORKDIR_BYTES) {
            return false;
        }
        if ($path === '/') {
            return true;
        }
        $segments = explode('/', substr($path, 1));
        if (end($segments) === '') {
            array_pop($segments);
        }
        if ($segments === [] || count($segments) > self::MAX_WORKDIR_SEGMENTS) {
            return false;
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9._-]+$/D', $segment) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** Meldung zum Namen oder null, wenn er erlaubt ist. */
    public static function envNameProblem(string $name): ?string
    {
        if (preg_match(self::ENV_NAME, $name) !== 1) {
            return 'Ungültiger Name. Erlaubt sind 1–64 Zeichen: Großbuchstaben, Ziffern und Unterstrich, nicht mit einer Ziffer beginnend.';
        }
        $upper = strtoupper($name);
        if (str_starts_with($upper, 'LD_') || str_starts_with($upper, 'MERIDIAN_') || in_array($upper, self::FORBIDDEN_ENV, true)) {
            return 'Dieser Name ist nicht erlaubt (LD_*, MERIDIAN_*, PATH, HOME, TZ, BASH_ENV, ENV, SHELLOPTS, BASHOPTS, IFS, PS4, PROMPT_COMMAND).';
        }

        return null;
    }

    /** Meldung zum Wert oder null. Nennt nie den Wert. */
    public static function envValueProblem(string $value): ?string
    {
        if (strlen($value) > self::MAX_ENV_VALUE_BYTES) {
            return 'Der Wert ist zu lang (höchstens 4096 Byte).';
        }
        if (preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            return 'Der Wert muss gültiger UTF-8-Text ohne NUL-Zeichen sein.';
        }

        return null;
    }

    /** Meldung zum Skript oder null. */
    public static function scriptProblem(string $script): ?string
    {
        if (strlen($script) > self::MAX_SCRIPT_BYTES) {
            return 'Das Skript ist zu groß (höchstens 256 KiB).';
        }
        if (preg_match('//u', $script) !== 1 || str_contains($script, "\0")) {
            return 'Das Skript muss gültiger UTF-8-Text ohne NUL-Zeichen sein.';
        }
        if (preg_match('/\S/u', $script) !== 1) {
            return 'Das Skript ist leer. Mindestens ein Befehl ist nötig.';
        }

        return null;
    }
}
