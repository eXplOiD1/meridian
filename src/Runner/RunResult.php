<?php

declare(strict_types=1);

namespace Meridian\Runner;

use Meridian\Schedule\RunStatus;

/**
 * Ergebnis eines Laufs. Ausgabe und Notiz sind Rohtext: der Worker maskiert und kürzt sie vor dem
 * Speichern.
 *
 * `retryable = false` heißt: ein Einrichtungsfehler (gesperrtes Ziel, gespeicherte Anfrage unlesbar, TLS-Prüfung
 * fehlgeschlagen …), den eine Wiederholung nicht behebt. Der Worker legt dann keine Wiederholung an.
 */
final readonly class RunResult
{
    private function __construct(
        public RunStatus $status,
        public string $output,
        public ?string $note,
        public ?int $exitCode,
        public ?int $httpStatus,
        public bool $retryable,
        public ?int $outputBytes = null,
    ) {
    }

    /**
     * Gelesene Rohbytes der Ausgabe (auch der nicht gespeicherten), für `runs.output_bytes` („N B ausgelassen“).
     */
    public function withOutputBytes(int $bytes): self
    {
        return new self($this->status, $this->output, $this->note, $this->exitCode, $this->httpStatus, $this->retryable, max(0, $bytes));
    }

    public static function ok(string $output = '', ?int $exitCode = null, ?int $httpStatus = null, ?string $note = null): self
    {
        return new self(RunStatus::Ok, $output, $note, $exitCode, $httpStatus, false);
    }

    public static function failed(string $output = '', ?string $note = null, ?int $exitCode = null, ?int $httpStatus = null, bool $retryable = true): self
    {
        return new self(RunStatus::Failed, $output, $note, $exitCode, $httpStatus, $retryable);
    }

    /**
     * Abgebrochen (Benutzer, Worker wird beendet). Nie wiederholbar (ADR 0004 §5.1).
     */
    public static function aborted(string $output, string $note): self
    {
        return new self(RunStatus::Aborted, $output, $note, null, null, false);
    }

    public static function timeout(string $output = '', ?string $note = null, ?int $httpStatus = null): self
    {
        return new self(RunStatus::Timeout, $output, $note, null, $httpStatus, true);
    }
}
