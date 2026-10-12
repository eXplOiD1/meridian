<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Nimmt das beim Lauf gesehene Ablaufdatum des Serverzertifikats auf (ADR 0010). Wirft nie: eine Warnung darf keinen Lauf kippen.
 */
interface TlsExpirySink
{
    public function record(int $jobId, int $expiresAt): void;
}
