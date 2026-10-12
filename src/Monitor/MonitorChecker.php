<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Monitor;

use Meridian\Auth\Clock;
use Meridian\Database\Timestamp;
use Meridian\Monitor\Probe\Prober;
use Meridian\Security\SecretBox;

/**
 * Führt fällige aktive Prüfungen aus (ADR 0018): im `worker-http`-Prozess, einmal je Wartungstakt. Eine Prüfung ist fällig, wenn
 * die letzte länger als das Intervall her ist; wer den bisherigen Zeitpunkt beansprucht, prüft (mehrere Worker prüfen nie doppelt).
 * Pro Takt höchstens {@see self::BUDGET_SECONDS} Sekunden Prüfzeit, damit der Worker nicht lange von Läufen abhält; der Rest folgt im
 * nächsten Takt (älteste zuerst). Die Meldungen stellt danach der Zusteller zu.
 */
final class MonitorChecker
{
    public const BUDGET_SECONDS = 20;

    public function __construct(
        private readonly MonitorRepository $monitors,
        private readonly SecretBox $box,
        private readonly Prober $prober,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<string> Zeilen für das Betriebsprotokoll (nur Anzahlen)
     */
    public function tick(): array
    {
        $now = $this->clock->now();
        $due = [];
        foreach ($this->monitors->activeForChecks() as $monitor) {
            $last = $monitor['last_check_at'];
            if ($last !== null) {
                try {
                    if ($now->getTimestamp() - Timestamp::parse($last)->getTimestamp() < $monitor['interval_seconds']) {
                        continue;
                    }
                } catch (\InvalidArgumentException) {
                    // unlesbarer Zeitpunkt: gilt als fällig
                }
            }
            $due[] = $monitor;
        }
        usort($due, static fn (array $a, array $b): int => strcmp((string) $a['last_check_at'], (string) $b['last_check_at']));

        $checked = 0;
        $failed = 0;
        $deadline = hrtime(true) + self::BUDGET_SECONDS * 1_000_000_000;
        foreach ($due as $monitor) {
            if (hrtime(true) >= $deadline) {
                break;
            }
            $stamp = Timestamp::format($this->clock->now());
            if (!$this->monitors->claimCheck($monitor['id'], $monitor['last_check_at'], $stamp)) {
                continue;
            }
            try {
                $target = $this->box->decrypt($monitor['target_enc']);
                $result = $this->prober->probe($monitor['kind'], $target, $monitor['category_id'], $monitor['expected_status']);
            } catch (\Throwable) {
                // Schlüssel falsch, Wert beschädigt: fester Text, nie der der Ausnahme.
                $result = \Meridian\Monitor\Probe\ProbeResult::failed(\Meridian\Monitor\Probe\NetworkProbe::NOTE_TARGET_UNREADABLE);
            }
            $this->monitors->recordCheck($monitor['id'], $result->ok, $result->latencyMs, $result->note, Timestamp::format($this->clock->now()));
            ++$checked;
            if (!$result->ok) {
                ++$failed;
            }
        }

        return $checked === 0 ? [] : ['Prüfungen: ' . $checked . ' ausgeführt, ' . $failed . ' fehlgeschlagen.'];
    }
}
