<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Status;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Http\ValidationFailed;
use Meridian\Security\AccessControl;
use Meridian\Security\AccessDenied;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;

/**
 * Statusseiten anlegen, ändern, löschen, Link erneuern (ADR 0012). Recht: `status_pages.edit` in der Kategorie der Seite (beim
 * Ändern in der alten **und** der neuen); die Einträge muss der Bearbeiter selbst sehen dürfen (`jobs.view`). Jede Aktion samt Audit
 * in einer Transaktion; das Audit nennt Titel und Feldnamen, nie den Slug.
 */
final class StatusPageService
{
    public const MAX_PAGES = 50;
    private const EDIT = Permission::EditStatusPages;

    public function __construct(
        private readonly Connection $db,
        private readonly StatusPageRepository $pages,
        private readonly StatusPageValidator $validator,
        private readonly AccessControl $access,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<RoleGrant> $grants
     * @param array<mixed>    $input
     *
     * @throws AccessDenied|ValidationFailed
     */
    public function create(int $userId, array $grants, array $input): StatusPageRecord
    {
        $editScope = $this->access->scope($grants, self::EDIT);
        if ($editScope->isEmpty()) {
            throw new AccessDenied(self::EDIT);
        }

        return $this->db->immediate(function () use ($userId, $grants, $input, $editScope): StatusPageRecord {
            $draft = $this->validator->validate($input, $editScope, $this->access->scope($grants, Permission::ViewJobs), null);
            $this->access->require($grants, self::EDIT, $draft->categoryName);
            if (!array_key_exists('title', $input)) {
                throw ValidationFailed::field('title', 'Pflichtfeld: Titel der Seite.');
            }
            $count = $this->db->fetchOne('SELECT COUNT(*) AS n FROM status_pages')['n'] ?? null;
            if (!is_int($count)) {
                throw new \LogicException('Anzahl der Statusseiten nicht lesbar.');
            }
            if ($count >= self::MAX_PAGES) {
                throw ValidationFailed::field('title', 'Höchstens ' . self::MAX_PAGES . ' Statusseiten. Nicht benötigte zuerst löschen.');
            }
            $id = $this->pages->insert($draft, $userId, StatusPageRepository::newSlug(), Timestamp::format($this->clock->now()));
            $this->audit->record($userId, 'status_page.created', 'status_page:' . $id . ' ' . $draft->title . ' (' . count($draft->items) . ' Einträge)');

            return $this->pages->findVisible($id, $editScope) ?? throw new \LogicException('Statusseite fehlt nach dem Anlegen.');
        });
    }

    /**
     * @param list<RoleGrant> $grants
     * @param array<mixed>    $input
     *
     * @return StatusPageRecord|null null = nicht sichtbar (→ 404)
     *
     * @throws AccessDenied|ValidationFailed
     */
    public function update(int $userId, array $grants, int $id, array $input): ?StatusPageRecord
    {
        $editScope = $this->access->scope($grants, self::EDIT);
        if ($editScope->isEmpty()) {
            throw new AccessDenied(self::EDIT);
        }

        return $this->db->immediate(function () use ($userId, $grants, $id, $input, $editScope): ?StatusPageRecord {
            $existing = $this->pages->findVisible($id, $editScope);
            if ($existing === null) {
                return null;
            }
            $draft = $this->validator->validate($input, $editScope, $this->access->scope($grants, Permission::ViewJobs), $existing);
            $this->access->require($grants, self::EDIT, $draft->categoryName);
            $this->pages->update($id, $draft, Timestamp::format($this->clock->now()));
            $changed = [];
            foreach ([
                'title' => $draft->title !== $existing->title,
                'category_id' => $draft->categoryId !== $existing->categoryId,
                'is_enabled' => $draft->isEnabled !== $existing->isEnabled,
                'items' => self::itemKeys($draft->items) !== self::itemKeys($existing->items),
            ] as $field => $differs) {
                if ($differs) {
                    $changed[] = $field;
                }
            }
            if ($changed !== []) {
                $this->audit->record($userId, 'status_page.updated', 'status_page:' . $id . ' ' . $draft->title . '; geändert: ' . implode(', ', $changed));
            }

            return $this->pages->findVisible($id, $editScope);
        });
    }

    /**
     * @param list<RoleGrant> $grants
     *
     * @return bool false = nicht sichtbar (→ 404)
     *
     * @throws AccessDenied
     */
    public function delete(int $userId, array $grants, int $id): bool
    {
        $editScope = $this->access->scope($grants, self::EDIT);
        if ($editScope->isEmpty()) {
            throw new AccessDenied(self::EDIT);
        }

        return $this->db->immediate(function () use ($userId, $id, $editScope): bool {
            $existing = $this->pages->findVisible($id, $editScope);
            if ($existing === null) {
                return false;
            }
            $this->pages->delete($id);
            $this->audit->record($userId, 'status_page.deleted', 'status_page:' . $id . ' ' . $existing->title);

            return true;
        });
    }

    /**
     * Erneuert den Link; der alte gilt sofort nicht mehr (z. B. nach versehentlichem Weitergeben).
     *
     * @param list<RoleGrant> $grants
     *
     * @return StatusPageRecord|null null = nicht sichtbar (→ 404)
     *
     * @throws AccessDenied
     */
    public function rotateSlug(int $userId, array $grants, int $id): ?StatusPageRecord
    {
        $editScope = $this->access->scope($grants, self::EDIT);
        if ($editScope->isEmpty()) {
            throw new AccessDenied(self::EDIT);
        }

        return $this->db->immediate(function () use ($userId, $id, $editScope): ?StatusPageRecord {
            $existing = $this->pages->findVisible($id, $editScope);
            if ($existing === null) {
                return null;
            }
            $this->pages->rotateSlug($id, StatusPageRepository::newSlug(), Timestamp::format($this->clock->now()));
            $this->audit->record($userId, 'status_page.link_renewed', 'status_page:' . $id . ' ' . $existing->title);

            return $this->pages->findVisible($id, $editScope);
        });
    }

    /**
     * @param list<StatusItem> $items
     *
     * @return list<string>
     */
    private static function itemKeys(array $items): array
    {
        return array_map(static fn (StatusItem $i): string => $i->type . ':' . $i->refId . ':' . $i->label, $items);
    }
}
