<?php

declare(strict_types=1);

namespace Meridian\Schedule;

/**
 * Ein Takt des Planer-Prozesses (ADR 0004 E5): Sperre übernehmen/verlängern, hängende und verwaiste Läufe beenden,
 * aufräumen, planen. Ausgeführt wird im Betrieb nichts: das tun die Worker-Prozesse (`worker:run`), deshalb halten
 * lange Läufe den Takt nie auf. Nur mit `$inlineWorker` (`scheduler:run --inline-worker`, Entwicklung und Tests)
 * arbeitet der Takt zusätzlich fällige Läufe selbst ab. Ohne Sperre passiert nichts.
 */
final class Scheduler
{
    private bool $holdsLease = false;

    public function __construct(
        private readonly SchedulerLease $lease,
        private readonly Planner $planner,
        private readonly StaleRuns $staleRuns,
        private readonly ?Worker $inlineWorker = null,
    ) {
    }

    /**
     * @param callable(): bool $stopRequested true → nichts Neues mehr anfangen (SIGTERM/SIGINT)
     *
     * @return list<RunEvent>
     */
    public function tick(callable $stopRequested): array
    {
        if ($stopRequested()) {
            return [];
        }
        if (!$this->lease->acquire()) {
            $this->holdsLease = false;

            return [];
        }

        $this->holdsLease = true;
        // Der eingebettete Worker meldet sich wie ein Worker-Prozess, damit seine Typen nicht als verwaist gelten.
        $this->inlineWorker?->announce(['inline' => true]);
        // Jeder Takt: Läufe ohne frischen Herzschlag beenden (abgestürzte Worker, Speicherfehler), verwaiste Läufe.
        $events = $this->staleRuns->abort();
        $this->staleRuns->cleanup();
        $events = [...$events, ...$this->planner->plan($stopRequested)];
        if ($this->inlineWorker !== null) {
            $events = [...$events, ...$this->inlineWorker->work($stopRequested)];
        }

        return $events;
    }

    public function holdsLease(): bool
    {
        return $this->holdsLease;
    }

    /**
     * @return list<\Meridian\Runner\JobType> Typen, deren fällige Läufe warten, ohne dass ein Worker lebt
     */
    public function typesWaitingWithoutWorker(): array
    {
        return $this->staleRuns->typesWaitingWithoutWorker();
    }

    public function hasInlineWorker(): bool
    {
        return $this->inlineWorker !== null;
    }

    /**
     * Beim Beenden: Sperre freigeben, damit ein Nachfolger sofort übernehmen kann.
     */
    public function shutdown(): void
    {
        if ($this->inlineWorker !== null) {
            try {
                $this->inlineWorker->retire();
            } catch (\Throwable) {
                // Zeile veraltet; der Planer räumt sie nach 1 h ab.
            }
        }
        if ($this->holdsLease) {
            $this->lease->release();
            $this->holdsLease = false;
        }
    }
}
