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
    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Zieldatei, z. B. /etc/meridian/master.key');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        if (!is_string($path) || $path === '') {
            $output->writeln('<error>Pfad fehlt.</error>');

            return self::INVALID;
        }

        $previous = umask(0o077);
        try {
            // Modus "x": legt die Datei nur an, wenn es sie noch nicht gibt (atomar, überschreibt nie).
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                $output->writeln('<error>Die Datei existiert bereits oder ist nicht beschreibbar. Ein bestehender Schlüssel wird nie überschrieben.</error>');

                return self::FAILURE;
            }
            $written = fwrite($handle, KeyLoader::generate() . "\n");
            fclose($handle);
            if ($written === false) {
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
