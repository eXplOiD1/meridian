<?php

declare(strict_types=1);

namespace Meridian\User;

/**
 * Eine Verwaltungsaktion wird abgelehnt (404, 403, 409, 429). Die Meldung ist ein fester deutscher Text, der sagt, was
 * falsch ist und wie man es behebt; sie enthält nie Eingabewerte oder Geheimnisse. Der Controller macht daraus die Antwort.
 */
final class UserRequestRefused extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        /** Nur bei 429: Wartezeit in Sekunden für `Retry-After`. */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(404, 'Benutzer nicht gefunden.');
    }

    public static function deleted(): self
    {
        return new self(409, 'Der Benutzer ist gelöscht und lässt sich nicht mehr ändern. Bei Bedarf einen neuen Benutzer anlegen.');
    }

    public static function self(): self
    {
        return new self(409, 'Das eigene Konto ändert man unter „Mein Konto“. Rollen, Aktivierung und Löschen ändert ein anderer Administrator.');
    }

    public static function mayNotManage(): self
    {
        return new self(403, 'Der Benutzer hat Rechte, die dir selbst fehlen. Nur wer alle diese Rechte besitzt, darf ihn verwalten.');
    }

    public static function mayNotAssign(): self
    {
        return new self(403, 'Diese Rolle enthält Rechte, die dir selbst fehlen. Vergeben kann man nur, was man selbst besitzt.');
    }

    public static function wrongPassword(): self
    {
        return new self(403, 'Passwort falsch.');
    }

    public static function changedMeanwhile(): self
    {
        return new self(409, 'Die Zuweisungen haben sich inzwischen geändert. Die Seite neu laden und erneut speichern.');
    }
}
