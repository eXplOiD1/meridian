<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Auth\AuditLog;
use Meridian\Security\SecretMasker;
use Meridian\Shell\InvalidShellTarget;
use Meridian\Shell\ShellTargetRecord;
use Meridian\Shell\ShellTargetStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ausführungsorte für Shell-Jobs an der Befehlszeile (docs/decisions/0004 §4.5):
 *
 *   shell:targets list
 *   shell:targets add docker nextcloud --user=www-data [--user=root] [--default-user=www-data] [--category=NAS] [--note=…]
 *   shell:targets add host default [--category=NAS]
 *   shell:targets remove <id>
 *
 * Dieselbe Prüfung wie die API ({@see ShellTargetStore::validate()}), Audit ohne Benutzer. Die Vertrauensgrenze ist
 * der Betriebssystem-Benutzer (mer-security §4). `root` gilt nur, wenn es ausdrücklich mit `--user=root` eingetragen
 * wird. Ohne `--default-user` gilt der einzige angegebene Benutzer als Standard.
 */
#[AsCommand(name: 'shell:targets', description: 'Listet, ergänzt oder entfernt Ausführungsorte für Shell-Jobs')]
final class ShellTargetsCommand extends Command
{
    public function __construct(
        private readonly ShellTargetStore $store,
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
            ->addArgument('first', InputArgument::OPTIONAL, 'add: Art (docker oder host); remove: Nummer des Ausführungsorts')
            ->addArgument('second', InputArgument::OPTIONAL, 'add: Container-Name (docker) oder Profilname (host)')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Nur für Jobs dieser Kategorie (Name); ohne Angabe global')
            ->addOption('user', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Erlaubter Benutzer im Container (mehrfach möglich; nur docker)')
            ->addOption('default-user', null, InputOption::VALUE_REQUIRED, 'Standardbenutzer (muss unter --user stehen)')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Notiz (höchstens 200 Zeichen)', '');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $first = self::text($input->getArgument('first'));

        return match (self::text($input->getArgument('action'))) {
            'list' => $this->list($output),
            'add' => $this->add($output, $first, self::text($input->getArgument('second')), $input->getOption('category'), $input->getOption('user'), $input->getOption('default-user'), $input->getOption('note')),
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
            $output->writeln('Keine Ausführungsorte. Shell-Jobs können erst nach einer Freigabe angelegt werden.');

            return self::SUCCESS;
        }
        foreach ($records as $record) {
            $output->writeln($this->line($record), OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }

    private function add(OutputInterface $output, string $kind, string $name, mixed $category, mixed $users, mixed $defaultUser, mixed $note): int
    {
        $categoryId = null;
        if ($category !== null) {
            $categoryId = is_string($category) ? $this->store->categoryIdByName($category) : null;
            if ($categoryId === null) {
                return $this->fail($output, 'Kategorie unbekannt. Namen genau wie angelegt angeben oder --category weglassen (gilt dann global).');
            }
        }
        $list = array_values(array_map(static fn (mixed $user): string => is_string($user) ? $user : '', is_array($users) ? $users : []));
        if ($defaultUser !== null && !is_string($defaultUser)) {
            return $this->fail($output, 'Der Standardbenutzer muss Text sein.');
        }
        if ($defaultUser === null && count($list) === 1) {
            $defaultUser = $list[0];
        }
        if (!is_string($note)) {
            return $this->fail($output, 'Die Notiz muss Text sein.');
        }

        try {
            $input = $this->store->validate($kind, $name, $categoryId, $list, $defaultUser, $note);
            $record = $this->store->add($input, null, $this->audit);
        } catch (InvalidShellTarget $e) {
            return $this->fail($output, $e->getMessage());
        }
        $output->writeln($this->masker->mask('Ausführungsort angelegt: ' . $this->line($record)), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function remove(OutputInterface $output, string $id): int
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/D', $id) !== 1) {
            return $this->fail($output, 'Nummer des Ausführungsorts fehlt oder ist ungültig (siehe „list“).');
        }
        $record = $this->store->remove((int) $id, null, $this->audit);
        if ($record === null) {
            return $this->fail($output, 'Ausführungsort nicht gefunden (siehe „list“).');
        }
        $output->writeln($this->masker->mask('Ausführungsort entfernt: ' . $this->line($record)), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function line(ShellTargetRecord $record): string
    {
        return $this->masker->mask('#' . $record->id . ' ' . $record->describe() . ($record->allowsRoot() ? ' [ROOT ERLAUBT]' : '') . ($record->note === '' ? '' : ' – ' . $record->note));
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln('<error>' . $message . '</error>');

        return self::INVALID;
    }
}
