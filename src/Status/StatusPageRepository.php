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
            'SELECT p.id, p.slug, p.title, p.category_id, c.name AS category_name, p.is_enabled, p.created_at, p.language
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
            'SELECT p.id, p.slug, p.title, p.category_id, c.name AS category_name, p.is_enabled, p.created_at, p.language
             FROM status_pages p LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id = :id AND (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))',
            $scope->sqlParameters() + ['id' => $id],
        );

        return $row === null ? null : $this->record($row);
    }

    public function insert(StatusPageDraft $draft, int $userId, string $slug, string $now): int
    {
        $this->db->execute(
            'INSERT INTO status_pages (slug, title, category_id, is_enabled, created_by, created_at, updated_at, language) VALUES (:slug, :title, :category, :enabled, :user, :now, :now, :language)',
            ['slug' => $slug, 'title' => $draft->title, 'category' => $draft->categoryId, 'enabled' => $draft->isEnabled ? 1 : 0, 'language' => $draft->language, 'user' => $userId, 'now' => $now],
        );
        $id = $this->db->lastInsertId();
        $this->replaceItems($id, $draft->items);

        return $id;
    }

    public function update(int $id, StatusPageDraft $draft, string $now): void
    {
        $this->db->execute(
            'UPDATE status_pages SET title = :title, category_id = :category, is_enabled = :enabled, language = :language, updated_at = :now WHERE id = :id',
            ['title' => $draft->title, 'category' => $draft->categoryId, 'enabled' => $draft->isEnabled ? 1 : 0, 'language' => $draft->language, 'now' => $now, 'id' => $id],
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
     * @return array{title: string, language: string, items: list<array{label: string, state: string, since: string|null, history: list<array{day: string, ok: int, bad: int}>, events: list<array{time: string, state: string}>, selected_day: string|null}>}|null
     */
    public function publicView(string $slug, ?\DateTimeImmutable $now = null, ?string $day = null, int $selectedItem = -1): ?array
    {
        $page = $this->db->fetchOne('SELECT id, title, language FROM status_pages WHERE slug = :slug AND is_enabled = 1', ['slug' => $slug]);
        if ($page === null) {
            return null;
        }
        $items = [];
        $rows = $this->db->fetchAll(
            'SELECT label, job_id, monitor_id FROM status_page_items WHERE page_id = :page ORDER BY position, id',
            ['page' => Row::int($page, 'id')],
        );
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        foreach ($rows as $position => $row) {
            $jobId = Row::intOrNull($row, 'job_id');
            [$state, $since] = $jobId !== null ? $this->jobState($jobId) : $this->monitorState(Row::int($row, 'monitor_id'));
            $id = $jobId ?? Row::int($row, 'monitor_id');
            $items[] = ['label' => Row::string($row, 'label'), 'state' => $state, 'since' => $since,
                'history' => $this->dailyHistory($id, $jobId !== null, $now),
                'events' => $position === $selectedItem && $day !== null ? $this->publicEvents($id, $jobId !== null, $day) : [],
                'selected_day' => $position === $selectedItem ? $day : null];
        }

        return ['title' => Row::string($page, 'title'), 'language' => Row::string($page, 'language'), 'items' => $items];
    }

    /** @return list<array{day: string, ok: int, bad: int}> */
    private function dailyHistory(int $id, bool $job, \DateTimeImmutable $now): array
    {
        $start = $now->setTime(0, 0)->modify('-89 days');
        $cutoff = \Meridian\Database\Timestamp::format($start);
        $rows = $job ? $this->db->fetchAll(
            "SELECT substr(COALESCE(started_at, finished_at), 1, 10) AS day, SUM(status = 'ok') AS ok, SUM(status IN ('failed', 'timeout', 'aborted')) AS bad
             FROM runs WHERE job_id = :id AND trigger <> 'test' AND COALESCE(started_at, finished_at) >= :cutoff
             AND status IN ('ok', 'failed', 'timeout', 'aborted') GROUP BY day", ['id' => $id, 'cutoff' => $cutoff])
            : $this->db->fetchAll(
            "SELECT substr(occurred_at, 1, 10) AS day, SUM(CASE WHEN ok = 1 THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN ok = 0 OR (kind IN ('state', 'alarm') AND state = 'down') THEN 1 ELSE 0 END) AS bad
             FROM monitor_events WHERE monitor_id = :id AND occurred_at >= :cutoff GROUP BY day", ['id' => $id, 'cutoff' => $cutoff]);
        $byDay = [];
        foreach ($rows as $row) { $byDay[Row::string($row, 'day')] = [Row::int($row, 'ok'), Row::int($row, 'bad')]; }
        $out = [];
        for ($offset = 0; $offset < 90; ++$offset) {
            $date = $start->modify('+' . $offset . ' days')->format('Y-m-d');
            [$ok, $bad] = $byDay[$date] ?? [0, 0];
            $out[] = ['day' => $date, 'ok' => $ok, 'bad' => $bad];
        }
        return $out;
    }

    /** Public events deliberately exclude internal IDs, names, targets, outputs and notification channels.
     * @return list<array{time: string, state: string}>
     */
    private function publicEvents(int $id, bool $job, string $day): array
    {
        $from = $day . 'T00:00:00+00:00';
        $to = \Meridian\Database\Timestamp::format((new \DateTimeImmutable($from))->modify('+1 day'));
        $rows = $job ? $this->db->fetchAll(
            "SELECT COALESCE(started_at, finished_at) AS time, CASE WHEN status = 'ok' THEN 'ok' ELSE 'down' END AS state FROM runs
             WHERE job_id = :id AND trigger <> 'test' AND status IN ('ok', 'failed', 'timeout', 'aborted')
             AND COALESCE(started_at, finished_at) >= :from AND COALESCE(started_at, finished_at) < :to ORDER BY id DESC LIMIT 100", ['id' => $id, 'from' => $from, 'to' => $to])
            : $this->db->fetchAll(
            "SELECT occurred_at AS time, CASE WHEN ok = 0 THEN 'down' WHEN ok = 1 THEN 'ok' WHEN state = 'up' THEN 'ok' WHEN state = 'down' THEN 'down' WHEN state = 'paused' THEN 'paused' ELSE 'unknown' END AS state FROM monitor_events
             WHERE monitor_id = :id AND occurred_at >= :from AND occurred_at < :to ORDER BY id DESC LIMIT 100", ['id' => $id, 'from' => $from, 'to' => $to]);
        return array_map(static fn (array $row): array => ['time' => Row::string($row, 'time'), 'state' => Row::string($row, 'state')], $rows);
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
        $monitor = $this->db->fetchOne('SELECT is_enabled, state, state_since, last_check_ok, last_check_at FROM monitors WHERE id = :id', ['id' => $monitorId]);
        if ($monitor === null) {
            return ['unknown', null];
        }
        if (!Row::bool($monitor, 'is_enabled')) {
            return ['paused', null];
        }
        if ($monitor['last_check_ok'] !== null && !Row::bool($monitor, 'last_check_ok') && Row::string($monitor, 'state') !== 'down') {
            return ['degraded', Row::stringOrNull($monitor, 'last_check_at')];
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
            Row::string($row, 'language'),
        );
    }
}
