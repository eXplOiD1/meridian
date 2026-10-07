<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Database\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate', description: 'Spielt ausstehende Datenbank-Migrationen ein')]
final class MigrateCommand extends Command
{
    public function __construct(private readonly Migrator $migrator)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $applied = $this->migrator->migrate();
        if ($applied === []) {
            $output->writeln('Datenbank ist aktuell.');

            return self::SUCCESS;
        }

        foreach ($applied as $name) {
            $output->writeln('Eingespielt: ' . $name);
        }

        return self::SUCCESS;
    }
}
