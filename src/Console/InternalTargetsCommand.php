<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Auth\AuditLog;
use Meridian\Network\InternalTargetRecord;
use Meridian\Network\InternalTargetStore;
use Meridian\Network\InvalidTargetInput;
use Meridian\Security\SecretMasker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Freigaben interner Ziele für HTTP-Jobs an der Befehlszeile (docs/decisions/0003, E5):
 *
 *   http:internal-targets list
 *   http:internal-targets add cidr 192.168.10.0/24 [--port=8080] [--category=NAS] [--note=…]
 *   http:internal-targets add host nextcloud [--port=443] [--category=NAS]
 *   http:internal-targets remove <id>
 *
 * Dieselbe Prüfung wie die API (`InternalTargetStore::validate()`, nie freigebbare Netze werden abgelehnt), Audit
 * ohne Benutzer. Die Vertrauensgrenze ist der Betriebssystem-Benutzer (mer-security §4).
 */
#[AsCommand(name: 'http:internal-targets', description: 'Listet, ergänzt oder entfernt Freigaben interner Ziele für HTTP-Jobs')]
final class InternalTargetsCommand extends Command
{
    public function __construct(
        private readonly InternalTargetStore $store,
        private readonly AuditLog $audit,
        private readonly SecretMasker $masker = new SecretMasker(),
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'list, add oder remove')
            ->addArgument('first', InputArgument::OPTIONAL, 'add: Art (cidr oder host); remove: Nummer der Freigabe')
            ->addArgument('second', InputArgument::OPTIONAL, 'add: Netz (192.168.10.0/24) oder Hostname (nextcloud)')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port (1–65535); ohne Angabe alle Ports', '0')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Nur für Jobs dieser Kategorie (Name); ohne Angabe global')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Notiz (höchstens 200 Zeichen)', '');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $first = self::text($input->getArgument('first'));

        return match (self::text($input->getArgument('action'))) {
            'list' => $this->list($output),
            'add' => $this->add($output, $first, self::text($input->getArgument('second')), $input->getOption('port'), $input->getOption('category'), $input->getOption('note')),
            'remove' => $this->remove($output, $first),
            default => $this->fail($output, 'Unbekannte Aktion. Erlaubt sind list, add und remove.'),
        };
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function list(OutputInterface $output): int
    {
        $records = $this->store->all();
        if ($records === []) {
            $output->writeln('Keine Freigaben. Interne Ziele sind für alle HTTP-Jobs gesperrt.');

            return self::SUCCESS;
        }
        foreach ($records as $record) {
            $output->writeln($this->line($record), OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }

    private function add(OutputInterface $output, string $kind, string $value, mixed $port, mixed $category, mixed $note): int
    {
        if (!is_string($port) || preg_match('/^(0|[1-9][0-9]{0,4})$/D', $port) !== 1) {
            return $this->fail($output, 'Port muss eine Zahl von 1 bis 65535 sein (ohne Angabe: alle Ports).');
        }
        $categoryId = null;
        if ($category !== null) {
            $categoryId = is_string($category) ? $this->store->categoryIdByName($category) : null;
            if ($categoryId === null) {
                return $this->fail($output, 'Kategorie unbekannt. Namen genau wie angelegt angeben oder --category weglassen (gilt dann global).');
            }
        }
        if (!is_string($note)) {
            return $this->fail($output, 'Die Notiz muss Text sein.');
        }

        try {
            $target = $this->store->validate($kind, $value, (int) $port, $categoryId, $note);
            $record = $this->store->add($target, $note, null);
        } catch (InvalidTargetInput $e) {
            return $this->fail($output, $e->getMessage());
        }
        $this->audit->record(null, 'network.internal_target_added', $record->describe());
        $output->writeln($this->masker->mask('Freigabe angelegt: ' . $this->line($record)), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function remove(OutputInterface $output, string $id): int
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/D', $id) !== 1) {
            return $this->fail($output, 'Nummer der Freigabe fehlt oder ist ungültig (siehe „list“).');
        }
        $record = $this->store->remove((int) $id);
        if ($record === null) {
            return $this->fail($output, 'Freigabe nicht gefunden (siehe „list“).');
        }
        $this->audit->record(null, 'network.internal_target_removed', $record->describe());
        $output->writeln($this->masker->mask('Freigabe entfernt: ' . $this->line($record)), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function line(InternalTargetRecord $record): string
    {
        return $this->masker->mask('#' . $record->id . ' ' . $record->describe() . ($record->note === '' ? '' : ' – ' . $record->note));
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln('<error>' . $message . '</error>');

        return self::INVALID;
    }
}
