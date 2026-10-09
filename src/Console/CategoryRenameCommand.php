<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Category\CategoryException;
use Meridian\Category\CategoryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `category:rename <name> <neuer-name>` (ADR 0005 E9). Die Kategorie wird über ihren Namen gefunden (ohne Rücksicht
 * auf Schreibung); der neue Name läuft durch dieselbe Allowlist wie in der API. Zuweisungen, Freigaben und Jobs hängen
 * an der ID und bleiben. Audit `category.renamed` ohne Benutzer (Vertrauensgrenze: Betriebssystem-Benutzer).
 * Eingaben werden nie ausgegeben.
 */
#[AsCommand(name: 'category:rename', description: 'Benennt eine Kategorie um')]
final class CategoryRenameCommand extends Command
{
    public function __construct(private readonly CategoryService $categories)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Bisheriger Name der Kategorie');
        $this->addArgument('new-name', InputArgument::REQUIRED, 'Neuer Name (1–64 Zeichen: Buchstaben, Ziffern, Leerzeichen, _ . -)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $new = $input->getArgument('new-name');
        if (!is_string($name) || !is_string($new)) {
            return self::INVALID;
        }
        $id = $this->categories->idByName($name);
        if ($id === null) {
            $output->writeln('<error>Kategorie nicht gefunden. Namen prüfen (Groß- und Kleinschreibung zählt nicht).</error>');

            return self::FAILURE;
        }
        try {
            $this->categories->rename($id, $new, null);
        } catch (CategoryException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }
        $output->writeln('Kategorie umbenannt (ID ' . $id . ').');

        return self::SUCCESS;
    }
}
