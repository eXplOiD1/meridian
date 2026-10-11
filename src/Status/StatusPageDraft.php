<?php

declare(strict_types=1);

namespace Meridian\Status;

final readonly class StatusPageDraft
{
    /**
     * @param list<StatusItem> $items
     */
    public function __construct(
        public string $title,
        public ?int $categoryId,
        public ?string $categoryName,
        public bool $isEnabled,
        public array $items,
    ) {
    }
}
