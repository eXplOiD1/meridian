<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\User\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\QuestionHelper;

#[AsCommand(name: 'user:create', description: 'Legt einen Benutzer an (Passwort wird verdeckt abgefragt)')]
final class UserCreateCommand extends Command
{
    public function __construct(private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Anzeigename')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Admin, Operator oder Beobachter', 'Beobachter');
        // Kein --password: Passwörter gehören nicht in die Shell-History oder die Prozessliste.
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getArgument('username');
        $name = $input->getOption('name');
        $role = $input->getOption('role');
        if (!is_string($username) || !is_string($role)) {
            return self::INVALID;
        }

        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return self::FAILURE;
        }

        $question = (new Question('Passwort (mind. 8 Zeichen): '))->setHidden(true)->setHiddenFallback(false);
        $password = $helper->ask($input, $output, $question);
        if (!is_string($password)) {
            $output->writeln('<error>Kein Passwort eingegeben.</error>');

            return self::INVALID;
        }

        $id = $this->users->create($username, is_string($name) ? $name : $username, $password, $role);
        $output->writeln('Benutzer angelegt (ID ' . $id . ', Rolle ' . $role . ').');

        return self::SUCCESS;
    }
}
