<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Lädt einen Job für den Shell-Runner, bei jedem Lauf frisch. Liest nur, schreibt nie.
 */
interface ShellJobSource
{
    /** null = Job gibt es nicht (mehr) oder die Zeile ist unlesbar. */
    public function load(int $jobId): ?StoredShellJob;
}
