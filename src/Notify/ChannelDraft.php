<?php

declare(strict_types=1);

namespace Meridian\Notify;

/**
 * Geprüfte Eingabe für einen Kanal. `secret`: null = unverändert lassen, '' = löschen, sonst ersetzen.
 */
final readonly class ChannelDraft
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $name,
        public ChannelType $type,
        public array $config,
        #[\SensitiveParameter] public ?string $secret,
        public bool $isEnabled,
    ) {
    }
}
