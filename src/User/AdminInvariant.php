<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Database\Connection;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;

/**
 * „Der letzte Admin bleibt“ (ADR 0005, E3), geprüft **nach** dem Schreiben in **derselben**
 * `Connection::immediate()`-Transaktion: so ist jeder Weg erfasst (Zuweisungen ersetzen, deaktivieren, löschen,
 * später ein Rollen-Editor), und zwei gleichzeitige Herabstufungen serialisieren sich — die zweite sieht das Ergebnis
 * der ersten und scheitert.
 *
 * Admin im Sinn der Invariante: aktiver, nicht gelöschter Benutzer, für den **jedes** Recht ohne Kategorie erlaubt ist
 * (`AccessControl::can($grants, $p, null)` für alle `Permission::cases()`), gelesen über `UserRepository::grantsFor()`
 * — dieselbe Quelle wie jede Anfrage. Wer noch ein Einmalpasswort hat, zählt deshalb nicht.
 *
 * Gilt auch für Rollenänderungen (ADR 0006 E4.5): Rechte einer Rolle setzen oder eine Rolle löschen ruft `assertHeld()`
 * in derselben Transaktion — etwa wenn eine eigene Rolle mit allen Rechten der einzige Admin-Weg ist. Kommt per
 * Migration ein neues Recht dazu, sind Inhaber eigener Rollen mit „allen“ Rechten keine Admins mehr (E2); `migrate`
 * warnt dann über {@see countAdmins()}.
 */
final class AdminInvariant
{
    private const CANDIDATES_SQL = 'SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id
      WHERE u.is_active = 1 AND u.deleted_at IS NULL AND u.password_must_change = 0 ORDER BY u.id';

    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
    ) {
    }

    /**
     * @throws LastAdminRemoved wenn kein Admin mehr bleibt (der Aufrufer rollt damit zurück)
     * @throws \LogicException  wenn außerhalb einer Transaktion aufgerufen (dann schützte die Prüfung nichts)
     */
    public function assertHeld(): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('AdminInvariant::assertHeld() nur in derselben Transaktion wie die Änderung aufrufen.');
        }

        if ($this->countAdmins() < 1) {
            throw new LastAdminRemoved();
        }
    }

    /** Anzahl der Admins im Sinn der Invariante (für Tests und Anzeige). */
    public function countAdmins(): int
    {
        $count = 0;
        foreach ($this->db->fetchAll(self::CANDIDATES_SQL) as $row) {
            if (isset($row['id']) && is_int($row['id']) && $this->isAdmin($row['id'])) {
                ++$count;
            }
        }

        return $count;
    }

    public function isAdmin(int $userId): bool
    {
        return $this->grantsAll($this->users->grantsFor($userId));
    }

    /**
     * Gewähren diese (frisch geladenen) Rechte **jedes** Recht des Katalogs ohne Kategorie? Dieselbe Regel wie
     * {@see isAdmin()}, für einen schon geladenen Handelnden (ADR 0006 F5: Ausnahme für volle Admins).
     *
     * @param list<\Meridian\Security\RoleGrant> $grants
     */
    public function grantsAll(array $grants): bool
    {
        if ($grants === []) {
            return false;
        }
        foreach (Permission::cases() as $permission) {
            if (!$this->access->can($grants, $permission, null)) {
                return false;
            }
        }

        return true;
    }
}
