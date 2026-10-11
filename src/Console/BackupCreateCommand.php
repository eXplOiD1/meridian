<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Auth\AuditLog;
use Meridian\Backup\BackupFailed;
use Meridian\Backup\BackupService;
use Meridian\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sichert die Datenbank (`VACUUM INTO`, geprüft, ohne Hauptschlüssel) nach `<Datenverzeichnis>/backups` und behält die
 * neuesten `--keep` Sicherungen. Läuft bei laufendem Betrieb.
 */
#[AsCommand(name: 'backup:create', description: 'Sichert die Datenbank (ohne Hauptschlüssel) und behält die neuesten Sicherungen')]
final class BackupCreateCommand extends Command
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly Connection $db,
        private readonly AuditLog $audit,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Wie viele Sicherungen behalten werden (1 bis 365)', '14');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keep = $input->getOption('keep');
        if (!is_string($keep) || preg_match('/^[1-9]\d{0,2}$/D', $keep) !== 1 || (int) $keep > 365) {
            $output->writeln('<error>--keep: ganze Zahl von 1 bis 365.</error>');

            return self::INVALID;
        }
        try {
            $info = $this->backups->create($this->db);
            $deleted = $this->backups->prune((int) $keep);
        } catch (BackupFailed $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }
        $this->audit->record(null, 'backup.created', $info->name);
        $output->writeln('Sicherung angelegt: ' . $info->name . ' (' . number_format($info->bytes / 1024, 0, ',', '.') . ' KiB).');
        if ($deleted > 0) {
            $output->writeln($deleted . ' ältere Sicherung(en) gelöscht (behalten: ' . $keep . ').');
        }
        $output->writeln('Der Hauptschlüssel ist nicht enthalten: Schlüsseldatei getrennt aufbewahren.');

        return self::SUCCESS;
    }
}
