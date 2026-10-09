<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Security\SecretMasker;

/**
 * Protokolliert eine unerwartete Ausnahme ohne ihre Meldung (CLAUDE.md Regel 4, mer-security §9).
 *
 * Die Meldung einer unerwarteten Ausnahme kann alles enthalten (SQL-Fehler mit Werten, eine URL mit Token aus
 * einer Bibliothek); der Masker an dieser Stelle kennt die Geheimnisse der Jobs nicht. Deshalb stehen im Log nur
 * die Klasse, Datei und Zeile und ein fester Text. Mit Datei und Zeile lässt sich die Ursache im Quelltext finden.
 */
final class ErrorLog
{
    public static function unexpected(#[\SensitiveParameter] \Throwable $e, string $where): void
    {
        // Die Zeile enthält keine Eingabe; der Masker (Muster) bleibt trotzdem davor (Regel 4).
        error_log((new SecretMasker())->mask(self::line($e, $where)));
    }

    /**
     * Die Protokollzeile (für Tests öffentlich).
     */
    public static function line(#[\SensitiveParameter] \Throwable $e, string $where): string
    {
        // Anonyme Klassen tragen nach einem NUL-Byte den Pfad der Datei; die Datei steht ohnehin dahinter.
        $class = strstr($e::class, "\0", true);

        return sprintf(
            'Meridian: Unerwartete Ausnahme %s in %s:%d (%s). Die Meldung wird nicht protokolliert, weil sie Geheimnisse enthalten kann; Ursache an der genannten Stelle prüfen.',
            $class === false ? $e::class : $class,
            $e->getFile(),
            $e->getLine(),
            $where,
        );
    }
}
