<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Auth\AuditLog;
use Meridian\Backup\BackupFailed;
use Meridian\Backup\BackupService;
use Meridian\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Spielt eine Sicherung aus dem Sicherungsverzeichnis ein (nur Dateiname, kein Pfad). Meridian muss angehalten sein;
 * vor dem ersten Schreiben wird geprüft, ob der Hauptschlüssel passt. Der bisherige Stand bleibt als
 * „…-vor-wiederherstellung“ erhalten.
 */
#[AsCommand(name: 'backup:restore', description: 'Spielt eine Sicherung ein (Meridian vorher anhalten)')]
final class BackupRestoreCommand extends Command
{
    /**
     * @param \Closure(): ?string $key        lädt den Hauptschlüssel (binär) oder liefert null, wenn keiner da ist
     * @param \Closure(): AuditLog $freshAudit Audit auf einer **neuen** Verbindung zur eingespielten Datenbank
     */
    public function __construct(
        private readonly BackupService $backups,
        private readonly Connection $db,
        private readonly \Closure $key,
        private readonly \Closure $freshAudit,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Dateiname der Sicherung, z. B. meridian-20261011-020000.sqlite (Liste: backup:list)');
        $this->addOption('yes', null, InputOption::VALUE_NONE, 'Bestätigt, dass der aktuelle Datenbestand ersetzt wird');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        if (!is_string($file)) {
            return self::INVALID;
        }
        if ($input->getOption('yes') !== true) {
            $output->writeln('<error>Die Wiederherstellung ersetzt den aktuellen Datenbestand. Zur Bestätigung --yes anhängen (der bisherige Stand wird vorher gesichert).</error>');

            return self::INVALID;
        }
        try {
            $info = $this->backups->restore($file, $this->db, ($this->key)());
        } catch (BackupFailed $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }
        try {
            ($this->freshAudit)()->record(null, 'backup.restored', $info->name);
        } catch (\Throwable) {
            $output->writeln('<comment>Hinweis: Der Audit-Eintrag konnte nicht geschrieben werden.</comment>');
        }
        $output->writeln('Wiederhergestellt: ' . $info->name . '. Der bisherige Stand liegt als Sicherung „…' . BackupService::PRE_RESTORE . '“ im Sicherungsverzeichnis.');
        $output->writeln('Nächster Schritt: bin/meridian migrate (falls die Sicherung älter ist), dann Meridian wieder starten.');

        return self::SUCCESS;
    }
}
