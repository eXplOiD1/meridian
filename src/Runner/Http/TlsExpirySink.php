<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Nimmt das beim Lauf gesehene Ablaufdatum des Serverzertifikats auf (ADR 0010). Wirft nie: eine Warnung darf keinen Lauf kippen.
 */
interface TlsExpirySink
{
    public function record(int $jobId, int $expiresAt): void;
}
