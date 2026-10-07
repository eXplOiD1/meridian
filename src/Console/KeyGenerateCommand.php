<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Security\KeyLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'key:generate', description: 'Erzeugt den Hauptschlüssel als Datei mit Rechten 0600')]
final class KeyGenerateCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Zieldatei, z. B. /etc/meridian/master.key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        if (!is_string($path) || $path === '') {
            $output->writeln('<error>Pfad fehlt.</error>');

            return self::INVALID;
        }

        if (file_exists($path)) {
            $output->writeln('<error>Datei existiert bereits. Ein bestehender Schlüssel wird nie überschrieben.</error>');

            return self::FAILURE;
        }

        $previous = umask(0o077);
        try {
            if (file_put_contents($path, KeyLoader::generate() . "\n") === false) {
                $output->writeln('<error>Datei konnte nicht geschrieben werden.</error>');

                return self::FAILURE;
            }
            chmod($path, 0o600);
        } finally {
            umask($previous);
        }

        // Der Schlüssel selbst wird bewusst nicht ausgegeben.
        $output->writeln('Schlüssel geschrieben nach ' . $path . ' (0600). Sicher aufbewahren: ohne ihn sind gespeicherte Geheimnisse verloren.');

        return self::SUCCESS;
    }
}
