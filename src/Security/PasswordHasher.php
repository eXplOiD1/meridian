<?php

declare(strict_types=1);

namespace Meridian\Security;

final class PasswordHasher
{
    private const MIN_LENGTH = 8;

    /**
     * Argon2id-Hash eines zufälligen, sofort verworfenen Werts (kein Geheimnis) mit den Standardkosten.
     * Die Anmeldung prüft einen unbekannten Benutzer dagegen, damit „unbekannt“ und „bekannt“ gleich lange
     * dauern. Er ist eine Konstante, weil ein Hash pro Anfrage zu berechnen den Zeitunterschied erst erzeugt.
     * Ändern sich die Kosten, schlägt der Test PasswordHasherTest::testDummyHashMatchesTheCurrentCost an.
     */
    public const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$eFBydWwxUEVzWWhYVHN0ZA$+KwT8BnfgdbyeOFtgHaMdZIvtIEtg4FXIDr/Qm34Tdk';

    /**
     * Erfüllt das Passwort die Regeln (mindestens 8 Zeichen)? Für Prüfungen vor einer teuren Operation.
     */
    public static function meetsPolicy(#[\SensitiveParameter] string $password): bool
    {
        return mb_strlen($password) >= self::MIN_LENGTH;
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        if (!self::meetsPolicy($password)) {
            throw new \InvalidArgumentException('Das Passwort muss mindestens 8 Zeichen lang sein.');
        }

        return password_hash($password, self::algorithm());
    }

    public function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        self::algorithm();

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    private static function algorithm(): string
    {
        // Kein stiller Rückfall auf bcrypt (schneidet nach 72 Byte ab): ohne Argon2id wird abgebrochen.
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new \RuntimeException('Argon2id ist in diesem PHP nicht verfügbar. Meridian verweigert die Passwortprüfung ohne Argon2id.');
        }

        return PASSWORD_ARGON2ID;
    }
}
