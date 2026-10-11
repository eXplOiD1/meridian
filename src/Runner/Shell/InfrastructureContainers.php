<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Meridians eigene Container: nie Ausführungsort eines Shell-Jobs, auch nicht mit Freigabe (docs/decisions/0004,
 * Nachtrag „Freigabe nur in der Oberfläche“). Der Docker-Proxy lässt Exec in jeden Container mit gültigem Namen
 * durch; die Freigabe in `shell_targets` und diese Sperre sind deshalb die Schichten, die zählen.
 *
 * Gesperrt (Groß-/Kleinschreibung egal): die Dienstnamen des Projekts (`web`, `scheduler`, `worker-http`,
 * `worker-shell`, `docker-proxy`), `meridian` und alles mit dem Präfix `meridian-` (Container-Namen aus compose.yaml,
 * auch `meridian-web-1`) — außer ausdrücklich `meridian-sandbox`, dem Standard-Ausführungsort.
 */
final class InfrastructureContainers
{
    /** Der einzige Name mit dem Präfix `meridian-`, der als Ausführungsort erlaubt ist. */
    public const ALLOWED_SANDBOX = 'meridian-sandbox';

    public const PREFIX = 'meridian-';

    /** Dienstnamen aus compose.yaml, die Meridians Betrieb tragen. */
    public const SERVICES = ['web', 'scheduler', 'worker-http', 'worker-shell', 'docker-proxy'];

    public const MESSAGE = 'Meridians eigene Container (web, scheduler, worker-http, worker-shell, docker-proxy und alles mit dem Präfix „meridian-“ außer „meridian-sandbox“) sind als Ausführungsort gesperrt. Einen anderen Container wählen.';

    private function __construct()
    {
    }

    public static function isInfrastructure(string $name): bool
    {
        $lower = strtolower($name);
        if ($lower === self::ALLOWED_SANDBOX) {
            return false;
        }

        return $lower === 'meridian' || str_starts_with($lower, self::PREFIX) || in_array($lower, self::SERVICES, true);
    }
}
