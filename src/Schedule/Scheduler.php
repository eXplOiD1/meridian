<?php

declare(strict_types=1);

namespace Meridian\Schedule;

/**
 * Ein Takt des Scheduler-Prozesses: Sperre übernehmen/verlängern, Läufe ohne Herzschlag abbrechen, planen,
 * fällige Läufe abarbeiten. Ohne Sperre passiert nichts.
 */
final class Scheduler
{
    private bool $holdsLease = false;

    public function __construct(
        private readonly SchedulerLease $lease,
        private readonly Planner $planner,
        private readonly Worker $worker,
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
        // Jeder Takt: Läufe ohne frischen Herzschlag beenden (fremde nach Absturz, eigene nach Speicherfehler).
        $events = $this->worker->abortStale();

        return [...$events, ...$this->planner->plan($stopRequested), ...$this->worker->work($stopRequested)];
    }

    public function holdsLease(): bool
    {
        return $this->holdsLease;
    }

    /**
     * Beim Beenden: Sperre freigeben, damit ein Nachfolger sofort übernehmen kann.
     */
    public function shutdown(): void
    {
        if ($this->holdsLease) {
            $this->lease->release();
            $this->holdsLease = false;
        }
    }
}
