<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dauerprozess des Schedulers. Phase 1: Takt-Schleife und Erkennung fälliger Jobs.
 * Phase 2 ergänzt Warteschlange, Überlappung, Wiederholen und verpasste Läufe.
 */
#[AsCommand(name: 'scheduler:run', description: 'Startet den Scheduler (Dauerprozess)')]
final class SchedulerRunCommand extends Command
{
    private bool $stop = false;

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Nur einen Durchlauf, dann beenden');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void { $this->stop = true; });
            pcntl_signal(SIGINT, function (): void { $this->stop = true; });
        }

        $once = $input->getOption('once') === true;
        do {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $due = $this->db->fetchAll(
                'SELECT id, name FROM jobs WHERE is_enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= :now',
                ['now' => $now->format('Y-m-d\TH:i:s\Z')],
            );
            $output->writeln($now->format('H:i:s') . ' fällig: ' . count($due), OutputInterface::VERBOSITY_VERBOSE);

            // TODO Phase 2: Läufe in die Warteschlange stellen und next_run_at neu berechnen.

            if (!$once) {
                // Bis zum Beginn der nächsten Minute schlafen.
                $sleep = 60 - (int) $now->format('s');
                for ($i = 0; $i < $sleep && !$this->stop; $i++) {
                    sleep(1);
                }
            }
        } while (!$once && !$this->stop);

        return self::SUCCESS;
    }
}
