<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Category\CategoryException;
use Meridian\Category\CategoryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * `category:delete <name> [--yes]` (ADR 0005 E9): löscht eine Kategorie nur, wenn kein Job (auch kein deaktivierter)
 * an ihr hängt. Die Folgen werden vorher angezeigt: Rollenzuweisungen verlieren die Kategorie (und werden, wenn es
 * ihre einzige war, wirkungslos, nie „alle“), Freigaben interner Ziele dieser Kategorie werden gelöscht, nie global.
 * Ohne `--yes` wird gefragt; ohne Terminal und ohne `--yes` passiert nichts. Audit `category.deleted` ohne Benutzer.
 */
#[AsCommand(name: 'category:delete', description: 'Löscht eine Kategorie ohne Jobs')]
final class CategoryDeleteCommand extends Command
{
    public function __construct(private readonly CategoryService $categories)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Name der Kategorie');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Ohne Rückfrage löschen');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        if (!is_string($name)) {
            return self::INVALID;
        }
        $id = $this->categories->idByName($name);
        $impact = $id === null ? null : $this->categories->impact($id);
        if ($impact === null) {
            $output->writeln('<error>Kategorie nicht gefunden. Namen prüfen (Groß- und Kleinschreibung zählt nicht).</error>');

            return self::FAILURE;
        }
        if ($impact->jobs > 0) {
            $output->writeln('<error>' . CategoryException::hasJobs($impact->jobs)->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln('Folgen: ' . $impact->assignments . ' Zuweisung(en) verlieren diese Kategorie, davon ' . $impact->assignmentsIneffective
            . ' werden wirkungslos (gewähren nichts); ' . $impact->internalTargets . ' Freigabe(n) interner Ziele werden gelöscht.');
        if ($input->getOption('yes') !== true) {
            $helper = $input->isInteractive() ? $this->getHelper('question') : null;
            if (!$helper instanceof QuestionHelper
                || $helper->ask($input, $output, new ConfirmationQuestion('Wirklich löschen? [j/N] ', false, '/^j/i')) !== true) {
                $output->writeln('<error>Nichts gelöscht. Zum Löschen ohne Rückfrage --yes angeben.</error>');

                return self::FAILURE;
            }
        }
        try {
            $this->categories->delete($impact->id, null);
        } catch (CategoryException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }
        $output->writeln('Kategorie gelöscht.');

        return self::SUCCESS;
    }
}
