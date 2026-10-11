<?php

declare(strict_types=1);

namespace Meridian\Notify;

/** Zustellung gescheitert: nur eine feste Meldung (nie Antwort, Host, Benutzer oder Passwort). */
final class SendFailed extends \RuntimeException
{
    public const TARGET = 'Ziel nicht erreichbar oder nicht erlaubt (Adresse gesperrt oder Name nicht auflösbar).';
    public const CONNECT = 'Verbindung zum Server fehlgeschlagen oder Zeitüberschreitung. Host, Port und Verschlüsselung prüfen.';
    public const TLS = 'Sichere Verbindung fehlgeschlagen. Verschlüsselung, Port und das Zertifikat des Servers prüfen.';
    public const AUTH = 'Anmeldung am Server abgelehnt. Benutzer und Passwort bzw. Token prüfen.';
    public const REJECTED = 'Der Server hat die Nachricht nicht angenommen. Absender, Empfänger und Thema prüfen.';
    public const SECRET = 'Das gespeicherte Passwort bzw. Token ist nicht lesbar. Im Kanal neu eingeben.';
    public const DISABLED = 'Der Kanal ist deaktiviert oder wurde gelöscht.';
}
