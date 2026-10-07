<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Lädt einen Job für den HTTP-Runner, bei jedem Lauf frisch. Liest nur, schreibt nie.
 */
interface HttpJobSource
{
    /** null = Job gibt es nicht (mehr). */
    public function load(int $jobId): ?StoredHttpJob;
}
