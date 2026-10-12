<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Http\ValidationFailed;
use Meridian\Security\Permission;

/**
 * Prüft die Felder einer Rollenanfrage streng (ADR 0006 §4): ablehnen, nie zurechtschneiden oder umdeuten
 * (`"jobs.view"` ist keine Liste, `5` ist kein Name). Welche Felder der Körper haben darf, prüft der Controller vorher
 * (unbekannte Felder → 422). Meldungen sind feste Texte mit Feldpfad (`permissions.3`), nie mit Eingabewert.
 */
final class RoleValidator
{
    public const MESSAGE_LIST = 'Rechte als Liste von Schlüsseln angeben, z. B. ["jobs.view", "jobs.run"].';
    public const MESSAGE_UNKNOWN = 'Unbekanntes Recht. Erlaubt sind nur die Schlüssel aus GET /api/roles.';
    public const MESSAGE_TWICE = 'Jedes Recht höchstens einmal angeben.';

    /**
     * @param array<mixed> $data Felder `name`, `permissions`, optional `description`
     *
     * @throws ValidationFailed
     */
    public function validateCreate(array $data): RoleDraft
    {
        $errors = [];
        $name = $data['name'] ?? null;
        if (!RoleName::isValid($name)) {
            $errors['name'] = RoleName::MESSAGE_NAME;
        }
        $description = $data['description'] ?? null;
        if (!RoleName::isValidDescription($description)) {
            $errors['description'] = RoleName::MESSAGE_DESCRIPTION;
        }
        $permissions = [];
        try {
            $permissions = $this->validatePermissions($data['permissions'] ?? null);
        } catch (ValidationFailed $failed) {
            $errors += $failed->fields();
        }
        if ($errors !== [] || !is_string($name) || ($description !== null && !is_string($description))) {
            throw new ValidationFailed($errors);
        }

        return new RoleDraft($name, $description, $permissions);
    }

    /**
     * @param array<mixed> $data Felder `name` und `description` (null = keine Beschreibung)
     *
     * @return array{name: string, description: string|null}
     *
     * @throws ValidationFailed
     */
    public function validateRename(array $data): array
    {
        $errors = [];
        $name = $data['name'] ?? null;
        if (!RoleName::isValid($name)) {
            $errors['name'] = RoleName::MESSAGE_NAME;
        }
        $description = $data['description'] ?? null;
        if (!RoleName::isValidDescription($description)) {
            $errors['description'] = RoleName::MESSAGE_DESCRIPTION;
        }
        if ($errors !== [] || !is_string($name) || ($description !== null && !is_string($description))) {
            throw new ValidationFailed($errors);
        }

        return ['name' => $name, 'description' => $description];
    }

    /**
     * Liste von Rechte-Schlüsseln → Rechte in Katalog-Reihenfolge. Leer ist erlaubt (Rolle ohne Rechte gewährt nichts).
     *
     * @return list<Permission>
     *
     * @throws ValidationFailed
     */
    public function validatePermissions(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > count(Permission::cases())) {
            throw ValidationFailed::field('permissions', self::MESSAGE_LIST);
        }
        $errors = [];
        $seen = [];
        foreach (array_keys($input) as $index) {
            $permission = is_string($input[$index]) ? Permission::tryFrom($input[$index]) : null;
            if ($permission === null) {
                $errors['permissions.' . $index] = self::MESSAGE_UNKNOWN;
            } elseif (isset($seen[$permission->value])) {
                $errors['permissions.' . $index] = self::MESSAGE_TWICE;
            } else {
                $seen[$permission->value] = true;
            }
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return array_values(array_filter(Permission::cases(), static fn (Permission $p): bool => isset($seen[$p->value])));
    }
}
