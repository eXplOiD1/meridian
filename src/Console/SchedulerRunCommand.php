<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Runner\JobType;
use Meridian\Schedule\RunEvent;
use Meridian\Schedule\Scheduler;
use Meridian\Security\SecretException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dauerprozess des Planers: alle {@see self::TICK_SECONDS} Sekunden ein Takt (Sperre, hängende Läufe, Aufräumen,
 * Planen). Ausgeführt wird nichts (ADR 0004 E5): das tun `worker:run --type=http|shell`. Der Planer braucht deshalb
 * keinen Schlüssel. Warten fällige Läufe eines Typs ohne lebenden Worker, warnt er einmal (bis wieder einer lebt).
 *
 * `--inline-worker` (nur Entwicklung/Tests und als Übergang für Installationen ohne Worker-Dienst): `$inline` baut
 * erst beim Start einen Scheduler mit eingebettetem Worker (lädt den Schlüssel, registriert die Runner); scheitert
 * es, endet der Befehl mit Exit 1, bevor er die Sperre übernimmt.
 *
 * SIGTERM/SIGINT setzen nur ein Flag: nichts Neues mehr anfangen, ein eingebetteter laufender Lauf wird abgebrochen
 * (`Worker::NOTE_WORKER_STOPPED`), Sperre freigeben, Exit 0. Ausgabe: nur Job-ID, Lauf-ID, Status und feste Texte.
 */
#[AsCommand(name: 'scheduler:run', description: 'Startet den Scheduler (Dauerprozess)')]
final class SchedulerRunCommand extends Command
{
    public const TICK_SECONDS = 5;

    private bool $stop = false;

    /**
     * @param (\Closure(): Scheduler)|null $inline baut den Scheduler mit eingebettetem Worker (`--inline-worker`)
     */
    public function __construct(
        private readonly Scheduler $planOnly,
        private readonly ?\Closure $inline = null,
    ) {
        parent::__construct();
    }

    /**
     * Beendet die Schleife nach dem aktuellen Schritt (für Signale und Tests).
     */
    public function requestStop(): void
    {
        $this->stop = true;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Genau einen Takt, dann beenden');
        $this->addOption('inline-worker', null, InputOption::VALUE_NONE, 'Läufe im Planer selbst ausführen (nur Entwicklung; braucht den Schlüssel)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduler = $this->planOnly;
        if ($input->getOption('inline-worker') === true) {
            if ($this->inline === null) {
                $output->writeln('<error>--inline-worker ist hier nicht verfügbar.</error>');

                return self::INVALID;
            }
            try {
                $scheduler = ($this->inline)();
            } catch (SecretException $e) {
                // Meldungen von KeyLoader/SecretBox sind feste Texte ohne Schlüssel.
                $output->writeln('<error>' . $e->getMessage() . '</error>');

                return self::FAILURE;
            } catch (\Throwable $e) {
                $output->writeln('<error>Start fehlgeschlagen (' . self::shortClass($e) . ').</error>');

                return self::FAILURE;
            }
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void { $this->requestStop(); });
            pcntl_signal(SIGINT, function (): void { $this->requestStop(); });
        }

        $once = $input->getOption('once') === true;
        $stopRequested = fn (): bool => $this->stop;
        $waiting = false;
        /** @var array<string, true> $warned */
        $warned = [];
        $lastWorkerCheck = 0.0;

        try {
            do {
                try {
                    $events = $scheduler->tick($stopRequested);
                } catch (\Throwable $e) {
                    // Ein Takt darf den Dienst nicht beenden (z. B. Datenbank kurz gesperrt). Die Meldung der
                    // Ausnahme kann Geheimnisse enthalten: nur die Klasse ausgeben, nie die Meldung.
                    $output->writeln('<error>Takt fehlgeschlagen (' . self::shortClass($e) . '). Der nächste Takt versucht es erneut.</error>');
                    $events = [];
                }

                if (!$scheduler->holdsLease() && !$waiting) {
                    $output->writeln('Ein anderer Scheduler hält die Sperre. Warte, bis sie frei wird.');
                }
                $waiting = !$scheduler->holdsLease();

                foreach ($events as $event) {
                    $output->writeln(self::describe($event));
                }
                if ($scheduler->holdsLease() && microtime(true) - $lastWorkerCheck >= 60.0) {
                    $lastWorkerCheck = microtime(true);
                    $warned = $this->warnMissingWorkers($scheduler, $warned, $output);
                }

                if (!$once) {
                    for ($i = 0; $i < self::TICK_SECONDS * 4 && !$this->stop; ++$i) {
                        usleep(250_000);
                    }
                }
            } while (!$once && !$this->stop);
        } finally {
            $scheduler->shutdown();
        }

        if ($this->stop) {
            $output->writeln('Scheduler beendet (Signal).', OutputInterface::VERBOSITY_VERBOSE);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, true> $warned
     *
     * @return array<string, true>
     */
    private function warnMissingWorkers(Scheduler $scheduler, array $warned, OutputInterface $output): array
    {
        try {
            $missing = array_map(static fn (JobType $t): string => $t->value, $scheduler->typesWaitingWithoutWorker());
        } catch (\Throwable) {
            return $warned;
        }
        foreach ($missing as $type) {
            if (!isset($warned[$type])) {
                $output->writeln(sprintf('<comment>Läufe vom Typ %1$s warten, aber kein Worker ist aktiv: worker:run --type=%1$s starten (Dienst worker-%1$s bzw. meridian-worker@%1$s).</comment>', $type));
            }
        }

        return array_fill_keys($missing, true);
    }

    private static function shortClass(\Throwable $e): string
    {
        $parts = explode('\\', $e::class);

        return end($parts);
    }

    private static function describe(RunEvent $event): string
    {
        return sprintf('Job %d, Lauf %d: %s', $event->jobId, $event->runId, $event->status->value);
    }
}
