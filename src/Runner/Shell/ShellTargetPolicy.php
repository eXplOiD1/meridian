<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Entscheidet, ob ein Job an einem Ausführungsort laufen darf (docs/decisions/0004 E4): beim Speichern (API) und
 * bei **jedem Lauf** (Worker, frisch, Kategorie des Jobs aus der Datenbank).
 *
 * Es muss eine Freigabe mit gleicher Art und gleichem Namen geben, die global gilt oder für genau die Kategorie des
 * Jobs. Ein Job ohne Kategorie nutzt nur globale Freigaben. Der Benutzer muss in der Liste einer passenden Freigabe
 * stehen (genau, kein Muster); `root` (und UID 0) also nur, wenn ein Admin es ausdrücklich eingetragen hat. Ohne
 * Benutzerangabe gilt der Standardbenutzer der Freigabe, nie der des Containers. `host` kennt keinen Benutzer.
 *
 * Meridians eigene Container ({@see InfrastructureContainers}) lehnt die Prüfung immer ab, auch mit Freigabe.
 *
 * Doppelt hält besser: auch wenn die Quelle fremde Freigaben liefert, zählen nur Zeilen, die zu Art, Name und
 * Kategorie passen.
 */
final class ShellTargetPolicy
{
    public function __construct(private readonly ShellTargetSource $source)
    {
    }

    /**
     * @return ShellTargetRefusal|null null = erlaubt
     */
    public function check(ShellTarget $target, ?string $user, ?int $categoryId): ?ShellTargetRefusal
    {
        if ($target->kind === ShellTargetKind::Docker && InfrastructureContainers::isInfrastructure($target->name)) {
            return ShellTargetRefusal::Infrastructure;
        }
        $grants = $this->matching($target, $categoryId);
        if ($grants === []) {
            return ShellTargetRefusal::TargetNotAllowed;
        }
        if ($target->kind === ShellTargetKind::Host) {
            return $user === null ? null : ShellTargetRefusal::UserNotAllowed;
        }
        if ($user === null) {
            return self::defaultUser($grants) === null ? ShellTargetRefusal::UserNotAllowed : null;
        }
        foreach ($grants as $grant) {
            if (in_array($user, $grant->users, true)) {
                return null;
            }
        }

        return ShellTargetRefusal::UserNotAllowed;
    }

    /**
     * Der Benutzer, unter dem der Befehl läuft: der angegebene (wenn erlaubt) oder der Standardbenutzer der Freigabe
     * (die der Kategorie vor der globalen). null bei `host` und wenn nichts erlaubt ist.
     */
    public function resolveUser(ShellTarget $target, ?string $user, ?int $categoryId): ?string
    {
        if ($target->kind === ShellTargetKind::Host || $this->check($target, $user, $categoryId) !== null) {
            return null;
        }

        return $user ?? self::defaultUser($this->matching($target, $categoryId));
    }

    /**
     * @return list<ShellTargetGrant> Freigaben der Kategorie zuerst, dann die globalen
     */
    public function grantsFor(ShellTarget $target, ?int $categoryId): array
    {
        return $this->matching($target, $categoryId);
    }

    /**
     * @return list<ShellTargetGrant>
     */
    private function matching(ShellTarget $target, ?int $categoryId): array
    {
        $own = [];
        $global = [];
        foreach ($this->source->grantsFor($target, $categoryId) as $grant) {
            if ($grant->target->kind !== $target->kind || $grant->target->name !== $target->name) {
                continue;
            }
            if ($grant->categoryId === null) {
                $global[] = $grant;
            } elseif ($categoryId !== null && $grant->categoryId === $categoryId) {
                $own[] = $grant;
            }
        }

        return [...$own, ...$global];
    }

    /**
     * @param list<ShellTargetGrant> $grants
     */
    private static function defaultUser(array $grants): ?string
    {
        foreach ($grants as $grant) {
            if ($grant->defaultUser !== null && in_array($grant->defaultUser, $grant->users, true)) {
                return $grant->defaultUser;
            }
        }

        return null;
    }
}
