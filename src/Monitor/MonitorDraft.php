<?php

declare(strict_types=1);

namespace Meridian\Monitor;

final readonly class MonitorDraft
{
    /** @param list<int> $channelIds */
    public function __construct(
        public string $name,
        public ?int $categoryId,
        public ?string $categoryName,
        public int $intervalSeconds,
        public int $graceSeconds,
        public bool $isEnabled,
        public bool $notifyOnDown,
        public bool $notifyOnUp,
        public string $notifyChannels,
        public MonitorKind $kind = MonitorKind::Heartbeat,
        /** Neues Ziel im Klartext (nur beim Anlegen oder Ersetzen); null = gespeichertes Ziel behalten. */
        #[\SensitiveParameter] public ?string $target = null,
        public ?string $displayTarget = null,
        public string $expectedStatus = '200-399',
        public int $failAfter = 3,
        public array $channelIds = [],
    ) {
    }
}
