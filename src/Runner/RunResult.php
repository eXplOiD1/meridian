<?php

declare(strict_types=1);

namespace Meridian\Runner;

use Meridian\Schedule\RunStatus;

/**
 * Ergebnis eines Laufs. Ausgabe und Notiz sind Rohtext: der Worker maskiert und kürzt sie vor dem
 * Speichern.
 */
final readonly class RunResult
{
    private function __construct(
        public RunStatus $status,
        public string $output,
        public ?string $note,
        public ?int $exitCode,
        public ?int $httpStatus,
    ) {
    }

    public static function ok(string $output = '', ?int $exitCode = null, ?int $httpStatus = null): self
    {
        return new self(RunStatus::Ok, $output, null, $exitCode, $httpStatus);
    }

    public static function failed(string $output = '', ?string $note = null, ?int $exitCode = null, ?int $httpStatus = null): self
    {
        return new self(RunStatus::Failed, $output, $note, $exitCode, $httpStatus);
    }

    public static function timeout(string $output = '', ?string $note = null): self
    {
        return new self(RunStatus::Timeout, $output, $note, null, null);
    }
}
