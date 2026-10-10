<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell\Docker;

/**
 * Start-Prüfung des Shell-Workers (docs/decisions/0004 E10): Meridians Freigaben (`shell_targets`, erste Schicht)
 * gegen `MERIDIAN_SHELL_CONTAINERS` (Pfad-Allowlist des Proxys, zweite Schicht). Nur Namen, keine Geheimnisse.
 */
final class DockerStartupCheck
{
    public const NO_ALLOWLIST = 'Warnung: MERIDIAN_SHELL_CONTAINERS ist nicht gesetzt: Der Docker-Proxy lässt dann nur seine Standardliste zu.';

    /**
     * @param list<mixed> $grantedContainers Namen der Docker-Freigaben (aus der Datenbank; anderes als Text wird übergangen)
     *
     * @return list<string> feste Warnungen mit Containernamen (validiert: `[A-Za-z0-9_-]`)
     */
    public static function warnings(array $grantedContainers, ?string $proxyAllowlist): array
    {
        if ($grantedContainers === []) {
            return [];
        }
        if ($proxyAllowlist === null || trim($proxyAllowlist) === '') {
            return [self::NO_ALLOWLIST];
        }
        $allowed = array_map('trim', explode('|', $proxyAllowlist));
        $lines = [];
        $seen = [];
        foreach ($grantedContainers as $name) {
            if (!is_string($name) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $name) !== 1 || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            if (!in_array($name, $allowed, true)) {
                $lines[] = 'Warnung: Ausführungsort ' . $name . ' fehlt in MERIDIAN_SHELL_CONTAINERS: Der Docker-Proxy lässt ihn nicht zu.';
            }
        }

        return $lines;
    }
}
