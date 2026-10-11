<?php

declare(strict_types=1);

namespace Meridian\Monitor;

final readonly class MonitorDraft
{
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
    ) {
    }
}
