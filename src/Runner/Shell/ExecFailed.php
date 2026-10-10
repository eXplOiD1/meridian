<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Start oder Ausführung gescheitert. Die Meldung ist immer einer der festen Texte unten (docs/decisions/0004 §5.3),
 * nie eine Meldung aus Docker, curl oder dem System; `retryable` sagt, ob eine Wiederholung helfen kann.
 */
final class ExecFailed extends \RuntimeException
{
    public const PROXY_UNREACHABLE = 'Docker-Proxy nicht erreichbar: Dienst docker-proxy prüfen (docker compose ps).';
    public const PROXY_DENIED = 'Der Docker-Proxy lässt diesen Container nicht zu: Namen in MERIDIAN_SHELL_CONTAINERS (compose/.env) eintragen.';
    public const PROXY_ERROR = 'Der Docker-Proxy hat einen unerwarteten Fehler gemeldet: Dienste docker-proxy und Docker prüfen.';
    public const CONTAINER_MISSING = 'Container nicht gefunden oder gestoppt.';
    public const CONNECTION_LOST = 'Verbindung zum Docker-Proxy abgebrochen.';
    public const PROTOCOL = 'Protokollfehler bei der Ausführung im Container: Lauf beendet. Hat der Container /bin/sh, head und cut?';
    public const HOST_NOT_SET_UP = 'Host-Ausführung nicht eingerichtet: meridian-shell.socket prüfen.';
    public const START_FAILED = 'Der Prozess konnte nicht gestartet werden.';
    public const NO_PROCESS_GROUP = 'Keine eigene Prozessgruppe (setsid): Lauf aus Sicherheitsgründen nicht gestartet.';
    public const API_TOO_OLD = 'Docker-API zu alt (mindestens 1.41): Docker auf dem Server aktualisieren.';
    public const INVALID_SPEC = 'Ungültige Angaben zum Ausführungsort (Name, Benutzer oder Arbeitsverzeichnis): Job neu speichern.';

    public function __construct(string $note, public readonly bool $retryable)
    {
        parent::__construct($note);
    }

    public static function proxyUnreachable(): self
    {
        return new self(self::PROXY_UNREACHABLE, true);
    }

    public static function proxyDenied(): self
    {
        return new self(self::PROXY_DENIED, false);
    }

    public static function proxyError(): self
    {
        return new self(self::PROXY_ERROR, true);
    }

    public static function containerMissing(): self
    {
        return new self(self::CONTAINER_MISSING, true);
    }

    public static function connectionLost(): self
    {
        return new self(self::CONNECTION_LOST, true);
    }

    public static function protocol(): self
    {
        return new self(self::PROTOCOL, false);
    }

    public static function invalidSpec(): self
    {
        return new self(self::INVALID_SPEC, false);
    }
}
