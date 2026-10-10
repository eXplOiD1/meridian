<?php

declare(strict_types=1);

namespace Meridian\User;

/**
 * Eine Rollenaktion wird abgelehnt (404, 403, 409, 429; ADR 0006 §5). Die Meldung ist ein fester deutscher Text, der sagt,
 * was falsch ist und wie man es behebt; sie enthält nie Eingabewerte. Zusätzliche Felder sind nur Anzahlen
 * (`assignments`, `restricted_assignments`). Der Controller macht daraus die Antwort.
 */
final class RoleRequestRefused extends \RuntimeException
{
    /**
     * @param array<string, int> $counts zusätzliche Felder der Antwort (nur Anzahlen)
     */
    public function __construct(
        public readonly int $status,
        string $message,
        /** Nur bei 429: Wartezeit in Sekunden für `Retry-After`. */
        public readonly ?int $retryAfter = null,
        public readonly array $counts = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(404, 'Rolle nicht gefunden.');
    }

    public static function adminFixed(): self
    {
        return new self(409, 'Die Rolle Admin hat immer alle Rechte und lässt sich weder ändern noch löschen.');
    }

    public static function builtinNotDeletable(): self
    {
        return new self(409, 'Standardrollen lassen sich nicht löschen. Bei Bedarf eine eigene Rolle anlegen.');
    }

    public static function ownRole(): self
    {
        return new self(409, 'Diese Rolle ist dir selbst zugewiesen. Rechte ändert ein anderer Administrator.');
    }

    public static function mayNotDefine(): self
    {
        return new self(403, 'Die Rolle enthält Rechte, die dir selbst fehlen (vorher oder nachher). Ändern kann man nur Rechte, die man selbst uneingeschränkt besitzt.');
    }

    public static function holderHasMore(): self
    {
        return new self(403, 'Diese Rolle hat ein Benutzer mit Rechten, die du nicht hast. Nur wer alle diese Rechte besitzt, darf die Rolle ändern.');
    }

    public static function restrictedAssignments(int $count): self
    {
        return new self(
            409,
            'Diese Rolle ist ' . $count . '-mal auf Kategorien beschränkt zugewiesen. Gefährliche Rechte gelten nur für alle Kategorien: zuerst die Zuweisungen auf „Alle Kategorien“ umstellen oder entfernen.',
            null,
            ['restricted_assignments' => $count],
        );
    }

    public static function stillAssigned(int $count): self
    {
        return new self(409, 'Die Rolle ist ' . $count . ' Benutzern zugewiesen. Zuweisungen zuerst ändern, dann löschen.', null, ['assignments' => $count]);
    }

    public static function tooManyRoles(int $max): self
    {
        return new self(409, 'Es gibt schon ' . $max . ' Rollen (Höchstzahl). Zuerst eine nicht mehr benötigte eigene Rolle löschen.');
    }

    public static function wrongPassword(): self
    {
        return new self(403, 'Passwort falsch.');
    }

    public static function changedMeanwhile(): self
    {
        return new self(409, 'Die Rolle hat sich inzwischen geändert. Die Seite neu laden und erneut speichern.');
    }
}
