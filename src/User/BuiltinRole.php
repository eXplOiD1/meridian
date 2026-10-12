<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\User;

/**
 * Stabile Kennung der drei Standardrollen (`roles.builtin_key`, ADR 0006 E1). Code, CLI und jede Migration ab 0013
 * sprechen Standardrollen nur hierüber an, nie über ihren Namen.
 *
 * Name und Beschreibung aller Standardrollen sind fest, keine ist löschbar (F1, F2). Rechte änderbar nur bei
 * Operator und Beobachter; die Admin-Rolle hat immer alle Rechte.
 */
enum BuiltinRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Observer = 'observer';

    /** Nur die Admin-Rolle hat feste Rechte (F2). */
    public function permissionsEditable(): bool
    {
        return match ($this) {
            self::Admin => false,
            self::Operator, self::Observer => true,
        };
    }
}
