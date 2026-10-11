<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Darf dieser Prozess ein Skript **selbst** starten ({@see LocalProcessExecutor})? Nur, wenn er weder im
 * Meridian-Container läuft noch Schlüssel oder Datenbank lesen kann und nicht root ist (docs/decisions/0004 E2/E3,
 * CLAUDE.md Sicherheitsnetz: „Shell-Jobs laufen nie im Meridian-Container und nie als Meridian-Benutzer“).
 *
 * Wird bei **jedem** Start frisch geprüft. Im Betrieb ist das nur der Host-Agent (`meridian-run`, ohne Zugriff auf
 * `/etc/meridian` und `/var/lib/meridian`); der Worker registriert nie einen lokalen Executor.
 */
final readonly class HostIsolation
{
    public const DEFAULT_CONTAINER_MARKERS = ['/.dockerenv', '/run/.containerenv'];
    public const DEFAULT_PROTECTED_PATHS = ['/etc/meridian', '/var/lib/meridian'];

    public const IN_CONTAINER = 'Lokale Ausführung im Container verweigert: Shell-Jobs laufen per Docker-Exec in freigegebenen Containern (docs/decisions/0004 E2).';
    public const AS_ROOT = 'Lokale Ausführung als root verweigert: Der Host-Agent läuft als eigener Benutzer (meridian-run).';
    public const CAN_READ_SECRETS = 'Lokale Ausführung verweigert: Dieser Prozess kann Schlüssel oder Datenbank von Meridian lesen (docs/decisions/0004 E2/E3).';

    /**
     * @param list<string> $containerMarkers gibt es eine dieser Dateien, läuft der Prozess in einem Container
     * @param list<string> $protectedPaths   Schlüsseldatei, Datenverzeichnis: lesbar → verweigert
     */
    public function __construct(
        private array $containerMarkers = self::DEFAULT_CONTAINER_MARKERS,
        private array $protectedPaths = self::DEFAULT_PROTECTED_PATHS,
        private bool $refuseRoot = true,
    ) {
    }

    /**
     * Schützt zusätzlich die Pfade aus der Umgebung (Schlüsseldatei, Datenverzeichnis).
     *
     * @param array<string, string> $env
     */
    public static function fromEnvironment(#[\SensitiveParameter] array $env): self
    {
        $paths = self::DEFAULT_PROTECTED_PATHS;
        foreach (['MERIDIAN_KEY_FILE', 'MERIDIAN_DATA_DIR'] as $name) {
            if (isset($env[$name]) && $env[$name] !== '') {
                $paths[] = $env[$name];
            }
        }

        return new self(self::DEFAULT_CONTAINER_MARKERS, array_values(array_unique($paths)));
    }

    /** null = erlaubt, sonst eine feste Meldung. */
    public function violation(): ?string
    {
        clearstatcache();
        foreach ($this->containerMarkers as $marker) {
            if (file_exists($marker)) {
                return self::IN_CONTAINER;
            }
        }
        if ($this->refuseRoot && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return self::AS_ROOT;
        }
        foreach ($this->protectedPaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return self::CAN_READ_SECRETS;
            }
        }

        return null;
    }
}
