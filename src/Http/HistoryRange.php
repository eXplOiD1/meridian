<?php
// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);
namespace Meridian\Http;

use Meridian\Database\Timestamp;
use Symfony\Component\HttpFoundation\Request;

final class HistoryRange
{
    /** @return array{?string, ?string} */
    public static function parse(Request $request): array
    {
        $range = [];
        foreach (['from', 'to'] as $name) {
            $value = $request->query->all()[$name] ?? null;
            if ($value === null) { $range[] = null; continue; }
            if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
                throw ValidationFailed::field($name, 'Zeitpunkt: ISO-8601 mit Zeitzone.');
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
            if ($date === false || $date->format('Y-m-d\TH:i:sP') !== str_replace('Z', '+00:00', $value)) {
                throw ValidationFailed::field($name, 'Zeitpunkt: ISO-8601 mit Zeitzone.');
            }
            $range[] = Timestamp::format($date);
        }
        if ($range[0] !== null && $range[1] !== null && $range[0] >= $range[1]) {
            throw ValidationFailed::field('to', 'Das Ende muss nach dem Beginn liegen.');
        }
        return [$range[0], $range[1]];
    }
}
