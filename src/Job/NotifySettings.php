<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

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
    public const MAX_DISABLE_AFTER = 1000;
    public const MAX_TLS_DAYS = 90;

    /**
     * @param list<int> $channelIds
     * @param list<string> $recipients
     */
    public function __construct(
        public bool $onFailure = false,
        public int $failureAfter = 1,
        public bool $onRecovery = false,
        public string $channels = self::ALL,
        public array $channelIds = [],
        /** Nach so vielen Fehlschlägen in Folge deaktiviert der Zusteller den Job (null = nie, ADR 0010). */
        public ?int $disableAfter = null,
        public bool $onDisable = false,
        /** Warnt, wenn das Zertifikat in so vielen Tagen abläuft (null = aus, ADR 0010). */
        public ?int $tlsWarnDays = null,
        public ?int $runtimeWarnPercent = null,
        public array $recipients = [],
    ) {
    }

    /**
     * @return array{on_failure: bool, failure_after: int, on_recovery: bool, channels: string, channel_ids: list<int>, disable_after: int|null, on_disable: bool, tls_warn_days: int|null, runtime_warn_percent: int|null, recipients: list<string>}
     */
    public function toArray(): array
    {
        return ['on_failure' => $this->onFailure, 'failure_after' => $this->failureAfter, 'on_recovery' => $this->onRecovery, 'channels' => $this->channels, 'channel_ids' => $this->channelIds, 'disable_after' => $this->disableAfter, 'on_disable' => $this->onDisable, 'tls_warn_days' => $this->tlsWarnDays, 'runtime_warn_percent' => $this->runtimeWarnPercent, 'recipients' => $this->recipients];
    }
}
