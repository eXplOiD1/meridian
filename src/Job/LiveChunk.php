<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Runner\LiveStream;

/**
 * Ein Stück des Live-Logs, wie lesende Endpunkte es sehen (`run_log_chunks`, beim Schreiben bereits maskiert; vor
 * der Ausgabe wird erneut maskiert).
 */
final readonly class LiveChunk
{
    /** Höchstens so viele Stücke je Abfrage (`GET /api/runs/{id}/log?limit=`). */
    public const MAX_LIMIT = 200;

    public function __construct(
        public int $seq,
        public LiveStream $stream,
        public string $text,
    ) {
    }
}
