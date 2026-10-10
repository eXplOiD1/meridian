<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Runner\JobType;
use Meridian\Runner\Shell\Docker\InvalidDockerProxy;
use Meridian\Schedule\RunEvent;
use Meridian\Schedule\Worker;
use Meridian\Schedule\WorkerSupervisor;
use Meridian\Security\SecretException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Worker-Prozesse (ADR 0004 E5, §5.2):
 *
 *   worker:run --type=http|shell [--concurrency=N]   Aufseher: startet N Kinder, lädt keinen Schlüssel
 *   worker:run --type=http|shell --child             Kind: lädt den Schlüssel, führt je einen Lauf zur Zeit aus
 *
 * Das Kind trägt sich in `workers` ein (`seen_at` alle 10 s), fragt jede Sekunde nach Arbeit und berührt die
 * Scheduler-Sperre nie. SIGTERM: nichts Neues mehr übernehmen, den laufenden Lauf abbrechen
 * (`Worker::NOTE_WORKER_STOPPED`), eigene Zeile löschen, Exit 0. Ausgabe nur Job-ID, Lauf-ID, Status und feste Texte.
 */
#[AsCommand(name: 'worker:run', description: 'Startet die Worker für einen Job-Typ (Dauerprozess)')]
final class WorkerRunCommand extends Command
{
    public const ANNOUNCE_SECONDS = 10;
    public const POLL_SECONDS = 1;
    public const MAX_CONCURRENCY = 32;
    /** Wartung im Kind (Shell: exec_ref-Aufräumen, ADR 0004 §5.2 Schritt 4). */
    public const MAINTENANCE_SECONDS = 60;

    private bool $stop = false;

    /**
     * @param \Closure(JobType): Worker $childWorker baut im Kind den Worker (lädt Schlüssel, registriert den Runner)
     * @param array<string, string>     $env         Umgebung des Aufsehers (wird für die Kinder gefiltert)
     * @param string                    $script      Pfad zu bin/meridian
     * @param (\Closure(JobType, bool): list<string>)|null $maintenance im Kind beim Start (true) und alle
     *                                   {@see self::MAINTENANCE_SECONDS} s; liefert feste Texte ohne Geheimnisse
     */
    public function __construct(
        private readonly \Closure $childWorker,
        private readonly array $env,
        private readonly string $script,
        private readonly ?\Closure $maintenance = null,
    ) {
        parent::__construct();
    }

    public function requestStop(): void
    {
        $this->stop = true;
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Job-Typ: http oder shell')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Anzahl Kinder (Standard http 4, shell 2; MERIDIAN_WORKER_CONCURRENCY)')
            ->addOption('child', null, InputOption::VALUE_NONE, 'Intern: ein Worker-Kind (vom Aufseher gestartet)')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Nur mit --child: einmal alles Fällige abarbeiten, dann beenden (Tests)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = JobType::tryFrom(SettingsGetCommand::text($input->getOption('type')));
        if ($type === null) {
            $output->writeln('<error>--type muss http oder shell sein.</error>');

            return self::INVALID;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void { $this->requestStop(); });
            pcntl_signal(SIGINT, function (): void { $this->requestStop(); });
        }

        if ($input->getOption('child') === true) {
            return $this->child($type, $input->getOption('once') === true, $output);
        }

        $concurrency = self::concurrency($input->getOption('concurrency'), $this->env['MERIDIAN_WORKER_CONCURRENCY'] ?? null, $type);
        if ($concurrency === null) {
            $output->writeln('<error>--concurrency bzw. MERIDIAN_WORKER_CONCURRENCY: ganze Zahl von 1 bis ' . self::MAX_CONCURRENCY . '.</error>');

            return self::INVALID;
        }
        $command = [PHP_BINARY, $this->script, 'worker:run', '--type=' . $type->value, '--child'];
        if ($output->isVerbose()) {
            $command[] = '-v';
        }
        $supervisor = new WorkerSupervisor(
            $command,
            WorkerSupervisor::childEnvironment($this->env, $type),
            $concurrency,
            static function (string $line) use ($output): void { $output->writeln($line, OutputInterface::VERBOSITY_VERBOSE); },
        );
        $output->writeln(sprintf('Worker-Aufseher für %s mit %d Kind(ern) gestartet.', $type->value, $concurrency), OutputInterface::VERBOSITY_VERBOSE);
        $supervisor->run(fn (): bool => $this->stop);
        $output->writeln('Worker-Aufseher beendet (Signal).', OutputInterface::VERBOSITY_VERBOSE);

        return self::SUCCESS;
    }

    private function child(JobType $type, bool $once, OutputInterface $output): int
    {
        try {
            $worker = ($this->childWorker)($type);
            $worker->announce();
        } catch (SecretException | InvalidDockerProxy $e) {
            // Meldungen von KeyLoader/SecretBox und zur Proxy-Einstellung sind feste Texte ohne Schlüssel oder Werte.
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Start fehlgeschlagen (' . self::shortClass($e) . ').</error>');

            return self::FAILURE;
        }

        $stopRequested = fn (): bool => $this->stop;
        $lastAnnounce = microtime(true);
        $this->maintain($type, true, $output);
        $lastMaintenance = microtime(true);
        try {
            do {
                $events = [];
                try {
                    if (microtime(true) - $lastAnnounce >= self::ANNOUNCE_SECONDS) {
                        $worker->announce();
                        $lastAnnounce = microtime(true);
                    }
                    if (microtime(true) - $lastMaintenance >= self::MAINTENANCE_SECONDS) {
                        $this->maintain($type, false, $output);
                        $lastMaintenance = microtime(true);
                    }
                    // Ein Lauf je Durchgang; --once (Tests) arbeitet alles Fällige einmal ab.
                    $events = $worker->work($stopRequested, $once ? Worker::MAX_RUNS_PER_TICK : 1);
                } catch (\Throwable $e) {
                    // Die Meldung kann Geheimnisse enthalten: nur die Klasse.
                    $output->writeln('<error>Durchgang fehlgeschlagen (' . self::shortClass($e) . '). Der nächste versucht es erneut.</error>');
                }
                foreach ($events as $event) {
                    $output->writeln(self::describe($event));
                }
                if (!$once && $events === []) {
                    for ($i = 0; $i < self::POLL_SECONDS * 10 && !$this->stop; ++$i) {
                        usleep(100_000);
                    }
                }
            } while (!$once && !$this->stop);
        } finally {
            try {
                $worker->retire();
            } catch (\Throwable) {
                // Zeile veraltet; der Planer räumt sie nach 1 h ab.
            }
        }
        if ($this->stop) {
            $output->writeln('Worker beendet (Signal).', OutputInterface::VERBOSITY_VERBOSE);
        }

        return self::SUCCESS;
    }

    private function maintain(JobType $type, bool $first, OutputInterface $output): void
    {
        if ($this->maintenance === null) {
            return;
        }
        try {
            foreach (($this->maintenance)($type, $first) as $line) {
                $output->writeln($line);
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Wartung fehlgeschlagen (' . self::shortClass($e) . ').</error>');
        }
    }

    /**
     * Anzahl Kinder: Option vor Umgebung vor Standard (http 4, shell 2); ungültig → null.
     */
    public static function concurrency(mixed $option, ?string $env, JobType $type): ?int
    {
        $text = is_string($option) ? $option : $env;
        if ($text === null || $text === '') {
            return match ($type) {
                JobType::Http => 4,
                JobType::Shell => 2,
            };
        }
        if (preg_match('/^[1-9][0-9]?$/D', $text) !== 1 || (int) $text > self::MAX_CONCURRENCY) {
            return null;
        }

        return (int) $text;
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
