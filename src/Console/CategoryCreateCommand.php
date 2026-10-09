<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Category\CategoryException;
use Meridian\Category\CategoryService;
use Meridian\Job\CategoryName;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `category:create <name>`: legt eine Kategorie an (docs/decisions/0003, O7). Bis es eine Verwaltung in der
 * Oberfläche gibt, ist das der einzige Weg.
 *
 * Der Name wird gegen eine Allowlist geprüft und bei Verstoß abgelehnt, nie zurechtgeschnitten. Doppelte Namen
 * (auch in anderer Groß-/Kleinschreibung) legen nichts an. Audit ohne Benutzer: Die Vertrauensgrenze ist der
 * Betriebssystem-Benutzer (mer-security §4). Eine neue Kategorie wird keiner Rolle zugewiesen; wer auf eine
 * Kategorieliste beschränkt ist, sieht sie nicht.
 */
#[AsCommand(name: 'category:create', description: 'Legt eine Kategorie für Jobs an')]
final class CategoryCreateCommand extends Command
{
    public function __construct(private readonly CategoryService $categories)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Name der Kategorie (1–64 Zeichen: Buchstaben, Ziffern, Leerzeichen, _ . -)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        if (!is_string($name) || !CategoryName::isValid($name)) {
            // Die Eingabe wird nicht wiederholt: Sie kann beliebig lang sein.
            $output->writeln('<error>Ungültiger Name. Erlaubt sind 1 bis ' . CategoryName::MAX_LENGTH . ' Zeichen: Buchstaben (A–Z), Ziffern, Leerzeichen, _ . und -. Der Name beginnt mit einem Buchstaben oder einer Ziffer und endet nicht mit einem Leerzeichen.</error>');

            return self::INVALID;
        }

        try {
            // Anlegen und Audit (ohne Benutzer) in einer Transaktion.
            $id = $this->categories->create($name, null);
        } catch (CategoryException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }
        $output->writeln('Kategorie angelegt (ID ' . $id . ').');

        return self::SUCCESS;
    }
}
