<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Security\Permission;

/**
 * Eine Rolle mit ihren Rechten, wie sie in `roles`/`role_permissions` steht: eine der drei Standardrollen
 * (`builtinKey` gesetzt) oder eine eigene Rolle (ADR 0006). `builtin` bleibt für bestehende Aufrufer erhalten.
 */
final readonly class Role
{
    /**
     * @param list<Permission> $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $builtin,
        public array $permissions,
        public ?BuiltinRole $builtinKey = null,
        public ?string $description = null,
    ) {
    }

    /** Standardrolle (Kennung oder altes Kennzeichen `is_builtin`): Name fest, nie löschbar. */
    public function isBuiltin(): bool
    {
        return $this->builtin || $this->builtinKey !== null;
    }

    /** Die Admin-Rolle: Rechte, Name und Beschreibung fest (F2). */
    public function isAdminRole(): bool
    {
        return $this->builtinKey === BuiltinRole::Admin;
    }

    /**
     * Rechte änderbar? Nie bei der Admin-Rolle; eine Standardrolle ohne Kennung (Name per Hand geändert, kann nach
     * Migration 0013 nicht entstehen) im Zweifel auch nicht.
     */
    public function permissionsEditable(): bool
    {
        if ($this->builtinKey !== null) {
            return $this->builtinKey->permissionsEditable();
        }

        return !$this->builtin;
    }

    /** Mindestens ein gefährliches Recht: nur mit „alle Kategorien“ zuweisbar, Bestätigung mit Passwort (E1, E7). */
    public function isDangerous(): bool
    {
        foreach ($this->permissions as $permission) {
            if ($permission->isDangerous()) {
                return true;
            }
        }

        return false;
    }
}
