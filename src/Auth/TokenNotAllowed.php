<?php

declare(strict_types=1);

namespace Meridian\Auth;

/** Ein gültiges Zugriffstoken darf diese Anfrage nicht stellen (anderer Bereich oder nur lesendes Token): 403 mit fester Meldung. */
final class TokenNotAllowed extends \RuntimeException
{
    public const AREA = 'Dieses Token darf diese Funktion nicht nutzen. Zugriffstoken gelten für Jobs, Läufe, Übersicht und Statistik.';
    public const READ_ONLY = 'Dieses Token darf nur lesen. Ein Token mit „Lesen und Schreiben“ wird im Konto angelegt.';
    public const SECRETS = 'Gespeicherte Werte werden nie über ein Zugriffstoken ausgegeben.';
}
