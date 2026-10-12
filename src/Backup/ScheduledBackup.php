<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Backup;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Database\Connection;

/**
 * Tägliche automatische Sicherung im Planer-Prozess (unter der Scheduler-Sperre). Sichert, wenn die neueste reguläre Sicherung
 * älter als {@see self::EVERY_SECONDS} ist, und behält die neuesten `$keep`. Ein Fehlschlag wird im Audit-Log
 * (`backup.failed`) sichtbar und höchstens stündlich wiederholt; der Takt des Planers wird nie angehalten.
 */
final class ScheduledBackup
{
    public const EVERY_SECONDS = 86_400;
    public const RETRY_SECONDS = 3_600;
    private const CHECK_SECONDS = 600;

    private float $lastCheck = 0.0;
    private float $lastFailure = 0.0;

    public function __construct(
        private readonly BackupService $backups,
        private readonly Connection $db,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly int $keep,
    ) {
        if ($keep < 1 || $keep > 365) {
            throw new \InvalidArgumentException('Anzahl der Sicherungen: 1 bis 365.');
        }
    }

    public function maybeRun(): bool
    {
        $now = microtime(true);
        if ($this->lastCheck !== 0.0 && $now - $this->lastCheck < self::CHECK_SECONDS) {
            return false;
        }
        $this->lastCheck = $now;
        if ($this->lastFailure !== 0.0 && $now - $this->lastFailure < self::RETRY_SECONDS) {
            return false;
        }
        $newest = null;
        foreach ($this->backups->list() as $info) {
            if (!str_contains($info->name, BackupService::PRE_RESTORE)) {
                $newest = $info;
                break;
            }
        }
        if ($newest !== null && $this->clock->now()->getTimestamp() - $newest->createdAt->getTimestamp() < self::EVERY_SECONDS) {
            return false;
        }
        try {
            $info = $this->backups->create($this->db);
            $this->backups->prune($this->keep);
            $this->audit->record(null, 'backup.created', $info->name);
        } catch (\Throwable $e) {
            $this->lastFailure = $now;
            // Nur die feste Meldung der Sicherung, nie die Meldung einer fremden Ausnahme.
            $this->audit->record(null, 'backup.failed', $e instanceof BackupFailed ? $e->getMessage() : 'Unbekannter Fehler');

            return false;
        }

        return true;
    }
}
