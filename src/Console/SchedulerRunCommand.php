<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Schedule\RunEvent;
use Meridian\Schedule\Scheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dauerprozess des Schedulers: alle {@see self::TICK_SECONDS} Sekunden ein Takt (Sperre, Planen, Ausführen).
 *
 * SIGTERM/SIGINT setzen nur ein Flag: die laufende Transaktion und ein laufender Lauf werden zu Ende geführt,
 * danach wird nichts Neues angefangen, die Sperre freigegeben und der Prozess beendet.
 * Ausgabe: nur Job-ID, Lauf-ID und Status, nie Payload oder Laufausgabe.
 */
#[AsCommand(name: 'scheduler:run', description: 'Startet den Scheduler (Dauerprozess)')]
final class SchedulerRunCommand extends Command
{
    public const TICK_SECONDS = 5;

    private bool $stop = false;

    public function __construct(private readonly Scheduler $scheduler)
    {
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
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Genau einen Takt (Planen und Ausführen), dann beenden');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void { $this->requestStop(); });
            pcntl_signal(SIGINT, function (): void { $this->requestStop(); });
        }

        $once = $input->getOption('once') === true;
        $stopRequested = fn (): bool => $this->stop;
        $waiting = false;

        try {
            do {
                try {
                    $events = $this->scheduler->tick($stopRequested);
                } catch (\Throwable $e) {
                    // Ein Takt darf den Dienst nicht beenden (z. B. Datenbank kurz gesperrt). Die Meldung der
                    // Ausnahme kann Geheimnisse enthalten: nur die Klasse ausgeben, nie die Meldung.
                    $output->writeln('<error>Takt fehlgeschlagen (' . self::shortClass($e) . '). Der nächste Takt versucht es erneut.</error>');
                    $events = [];
                }

                if (!$this->scheduler->holdsLease() && !$waiting) {
                    $output->writeln('Ein anderer Scheduler hält die Sperre. Warte, bis sie frei wird.');
                }
                $waiting = !$this->scheduler->holdsLease();

                foreach ($events as $event) {
                    $output->writeln(self::describe($event));
                }

                if (!$once) {
                    for ($i = 0; $i < self::TICK_SECONDS * 4 && !$this->stop; ++$i) {
                        usleep(250_000);
                    }
                }
            } while (!$once && !$this->stop);
        } finally {
            $this->scheduler->shutdown();
        }

        if ($this->stop) {
            $output->writeln('Scheduler beendet (Signal).', OutputInterface::VERBOSITY_VERBOSE);
        }

        return self::SUCCESS;
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
