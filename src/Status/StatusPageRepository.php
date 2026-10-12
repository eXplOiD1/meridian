<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Status;

use Meridian\Database\Connection;
use Meridian\Job\Row;
use Meridian\Security\CategoryScope;

/**
 * Zugriff auf `status_pages` und `status_page_items`. Verwaltungs-Abfragen benutzen das gemeinsame Sichtbarkeits-Prädikat der
 * Kategorie der Seite; die öffentliche Abfrage kennt nur den Slug und liefert ausschließlich Anzeigenamen und Zustände.
 */
final class StatusPageRepository
{
    private const SLUG_ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * 24 Zeichen aus 120 Bit Zufall.
     */
    public static function newSlug(): string
    {
        $bytes = random_bytes(15);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $slug = '';
        foreach (str_split($bits, 5) as $chunk) {
            $slug .= self::SLUG_ALPHABET[(int) bindec($chunk)];
        }

        return $slug;
    }

    /**
     * @return list<StatusPageRecord>
     */
    public function list(CategoryScope $scope): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.id, p.slug, p.title, p.category_id, c.name AS category_name, p.is_enabled, p.created_at
             FROM status_pages p LEFT JOIN categories c ON c.id = p.category_id
             WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))
             ORDER BY p.title COLLATE NOCASE, p.id',
            $scope->sqlParameters(),
        );

        return array_map(fn (array $row): StatusPageRecord => $this->record($row), $rows);
    }

    public function findVisible(int $id, CategoryScope $scope): ?StatusPageRecord
    {
        $row = $this->db->fetchOne(
            'SELECT p.id, p.slug, p.title, p.category_id, c.name AS category_name, p.is_enabled, p.created_at
             FROM status_pages p LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id = :id AND (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))',
            $scope->sqlParameters() + ['id' => $id],
        );

        return $row === null ? null : $this->record($row);
    }

    public function insert(StatusPageDraft $draft, int $userId, string $slug, string $now): int
    {
        $this->db->execute(
            'INSERT INTO status_pages (slug, title, category_id, is_enabled, created_by, created_at, updated_at) VALUES (:slug, :title, :category, :enabled, :user, :now, :now)',
            ['slug' => $slug, 'title' => $draft->title, 'category' => $draft->categoryId, 'enabled' => $draft->isEnabled ? 1 : 0, 'user' => $userId, 'now' => $now],
        );
        $id = $this->db->lastInsertId();
        $this->replaceItems($id, $draft->items);

        return $id;
    }

    public function update(int $id, StatusPageDraft $draft, string $now): void
    {
        $this->db->execute(
            'UPDATE status_pages SET title = :title, category_id = :category, is_enabled = :enabled, updated_at = :now WHERE id = :id',
            ['title' => $draft->title, 'category' => $draft->categoryId, 'enabled' => $draft->isEnabled ? 1 : 0, 'now' => $now, 'id' => $id],
        );
        $this->replaceItems($id, $draft->items);
    }

    public function rotateSlug(int $id, string $slug, string $now): void
    {
        $this->db->execute('UPDATE status_pages SET slug = :slug, updated_at = :now WHERE id = :id', ['slug' => $slug, 'now' => $now, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM status_pages WHERE id = :id', ['id' => $id]);
    }

    /**
     * Öffentliche Sicht: nur eine aktive Seite, nur Titel und die Einträge mit Anzeigename und Zustand.
     *
     * @return array{title: string, items: list<array{label: string, state: string, since: string|null}>}|null
     */
    public function publicView(string $slug): ?array
    {
        $page = $this->db->fetchOne('SELECT id, title FROM status_pages WHERE slug = :slug AND is_enabled = 1', ['slug' => $slug]);
        if ($page === null) {
            return null;
        }
        $items = [];
        $rows = $this->db->fetchAll(
            'SELECT label, job_id, monitor_id FROM status_page_items WHERE page_id = :page ORDER BY position, id',
            ['page' => Row::int($page, 'id')],
        );
        foreach ($rows as $row) {
            $jobId = Row::intOrNull($row, 'job_id');
            [$state, $since] = $jobId !== null ? $this->jobState($jobId) : $this->monitorState(Row::int($row, 'monitor_id'));
            $items[] = ['label' => Row::string($row, 'label'), 'state' => $state, 'since' => $since];
        }

        return ['title' => Row::string($page, 'title'), 'items' => $items];
    }

    /**
     * @return array{string, string|null} ok | down | paused | unknown und der Zeitpunkt des letzten Laufs
     */
    private function jobState(int $jobId): array
    {
        $job = $this->db->fetchOne('SELECT is_enabled FROM jobs WHERE id = :id', ['id' => $jobId]);
        if ($job === null) {
            return ['unknown', null];
        }
        $run = $this->db->fetchOne(
            "SELECT status, finished_at FROM runs WHERE job_id = :id AND trigger <> 'test' AND status IN ('ok', 'failed', 'timeout', 'aborted') ORDER BY id DESC LIMIT 1",
            ['id' => $jobId],
        );
        $since = $run === null ? null : Row::stringOrNull($run, 'finished_at');
        if (!Row::bool($job, 'is_enabled')) {
            return ['paused', $since];
        }
        if ($run === null) {
            return ['unknown', null];
        }

        return [Row::string($run, 'status') === 'ok' ? 'ok' : 'down', $since];
    }

    /**
     * @return array{string, string|null}
     */
    private function monitorState(int $monitorId): array
    {
        $monitor = $this->db->fetchOne('SELECT is_enabled, state, state_since FROM monitors WHERE id = :id', ['id' => $monitorId]);
        if ($monitor === null) {
            return ['unknown', null];
        }
        if (!Row::bool($monitor, 'is_enabled')) {
            return ['paused', null];
        }

        return match (Row::string($monitor, 'state')) {
            'up' => ['ok', Row::string($monitor, 'state_since')],
            'down' => ['down', Row::string($monitor, 'state_since')],
            default => ['unknown', null],
        };
    }

    /**
     * @param list<StatusItem> $items
     */
    private function replaceItems(int $id, array $items): void
    {
        $this->db->execute('DELETE FROM status_page_items WHERE page_id = :id', ['id' => $id]);
        foreach ($items as $position => $item) {
            $this->db->execute(
                'INSERT INTO status_page_items (page_id, position, label, job_id, monitor_id) VALUES (:page, :position, :label, :job, :monitor)',
                [
                    'page' => $id,
                    'position' => $position,
                    'label' => $item->label,
                    'job' => $item->type === StatusItem::JOB ? $item->refId : null,
                    'monitor' => $item->type === StatusItem::MONITOR ? $item->refId : null,
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function record(array $row): StatusPageRecord
    {
        $id = Row::int($row, 'id');
        $items = [];
        $rows = $this->db->fetchAll(
            'SELECT i.label, i.job_id, i.monitor_id, j.name AS job_name, m.name AS monitor_name
             FROM status_page_items i LEFT JOIN jobs j ON j.id = i.job_id LEFT JOIN monitors m ON m.id = i.monitor_id
             WHERE i.page_id = :page ORDER BY i.position, i.id',
            ['page' => $id],
        );
        foreach ($rows as $item) {
            $jobId = Row::intOrNull($item, 'job_id');
            $items[] = $jobId !== null
                ? new StatusItem(StatusItem::JOB, $jobId, Row::string($item, 'label'), Row::stringOrNull($item, 'job_name'))
                : new StatusItem(StatusItem::MONITOR, Row::int($item, 'monitor_id'), Row::string($item, 'label'), Row::stringOrNull($item, 'monitor_name'));
        }

        return new StatusPageRecord(
            $id,
            Row::string($row, 'slug'),
            Row::string($row, 'title'),
            Row::intOrNull($row, 'category_id'),
            Row::stringOrNull($row, 'category_name'),
            Row::bool($row, 'is_enabled'),
            $items,
            Row::string($row, 'created_at'),
        );
    }
}
