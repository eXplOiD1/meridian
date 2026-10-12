<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;

/**
 * {@see TlsExpirySink} in `job_tls`. Ein anderes Ablaufdatum (erneuertes Zertifikat) setzt die Warnung zurück.
 */
final class DbTlsExpirySink implements TlsExpirySink
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    #[\Override]
    public function record(int $jobId, int $expiresAt): void
    {
        try {
            $this->db->execute(
                'INSERT INTO job_tls (job_id, expires_at, checked_at) SELECT :id, :exp, :now WHERE EXISTS (SELECT 1 FROM jobs WHERE id = :id)
                 ON CONFLICT (job_id) DO UPDATE SET expires_at = :exp, checked_at = :now',
                ['id' => $jobId, 'exp' => Timestamp::format((new \DateTimeImmutable('@' . $expiresAt))), 'now' => Timestamp::format($this->clock->now())],
            );
        } catch (\Throwable) {
            // Wirft nie (siehe Schnittstelle).
        }
    }
}
