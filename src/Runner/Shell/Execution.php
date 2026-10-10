<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Ein laufender Befehl. Prozessgruppe statt einzelner PID: {@see self::terminate()} und {@see self::kill()} treffen
 * auch Kindprozesse. {@see self::close()} räumt immer auf (beendet einen noch laufenden Befehl, reapt), idempotent.
 */
interface Execution
{
    /**
     * Wartet höchstens `$maxWait` Sekunden; null = nichts Neues. Blöcke ≤ 64 KiB, rohe (unmaskierte) Bytes.
     *
     * @throws ExecFailed Verbindung oder Protokoll gestört (feste Meldung)
     */
    public function read(float $maxWait): ?OutputBlock;

    /** Prozess beendet und Ausgabe vollständig gelesen. */
    public function finished(): bool;

    /** Exit-Code nach dem Ende (null, solange er läuft oder bei Ende durch Signal). */
    public function exitCode(): ?int;

    /** Signal, das den Prozess beendet hat (nur lokal bekannt), sonst null. */
    public function termSignal(): ?int;

    /** SIGTERM an die Prozessgruppe. */
    public function terminate(): void;

    /** SIGKILL an die Prozessgruppe. */
    public function kill(): void;

    /** Für `runs.exec_ref` (Docker), sonst null. Nie ausgeben (H2). */
    public function ref(): ?ExecRef;

    /**
     * Feste Hinweise für die Notiz (z. B. Container ohne setsid), nie Text aus dem System.
     *
     * @return list<string>
     */
    public function notes(): array;

    public function close(): void;
}
