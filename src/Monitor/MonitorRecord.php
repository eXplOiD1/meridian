<?php

declare(strict_types=1);

namespace Meridian\Monitor;

/**
 * Gespeicherte Überwachung. Der Token-Hash gehört nie hierher (er wird nur zum Prüfen gelesen).
 */
final readonly class MonitorRecord
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $categoryId,
        public ?string $categoryName,
        public int $intervalSeconds,
        public int $graceSeconds,
        public bool $isEnabled,
        public string $state,
        public string $stateSince,
        public ?string $lastPingAt,
        public bool $notifyOnDown,
        public bool $notifyOnUp,
        public string $notifyChannels,
        public string $createdAt,
        public MonitorKind $kind = MonitorKind::Heartbeat,
        public ?string $displayTarget = null,
        public string $expectedStatus = '200-399',
        public int $failAfter = 3,
        public int $consecutiveFailures = 0,
        public ?string $lastCheckAt = null,
        public ?bool $lastCheckOk = null,
        public ?int $lastLatencyMs = null,
        public ?string $lastCheckNote = null,
    ) {
    }
}
