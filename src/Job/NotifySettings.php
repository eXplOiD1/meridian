<?php

declare(strict_types=1);

namespace Meridian\Job;

/**
 * Benachrichtigungseinstellungen eines Jobs (ADR 0007 N4). `channels`: all = alle aktiven Kanäle, selected = nur
 * `channelIds`, none = keine. `channelIds` ist nur bei `selected` belegt.
 */
final readonly class NotifySettings
{
    public const ALL = 'all';
    public const SELECTED = 'selected';
    public const NONE = 'none';
    public const MAX_AFTER = 100;
    public const MAX_CHANNELS = 20;

    /**
     * @param list<int> $channelIds
     */
    public function __construct(
        public bool $onFailure = false,
        public int $failureAfter = 1,
        public bool $onRecovery = false,
        public string $channels = self::ALL,
        public array $channelIds = [],
    ) {
    }

    /**
     * @return array{on_failure: bool, failure_after: int, on_recovery: bool, channels: string, channel_ids: list<int>}
     */
    public function toArray(): array
    {
        return ['on_failure' => $this->onFailure, 'failure_after' => $this->failureAfter, 'on_recovery' => $this->onRecovery, 'channels' => $this->channels, 'channel_ids' => $this->channelIds];
    }
}
