<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\User\PasswordReset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'user:password', description: 'Setzt das Passwort eines Benutzers neu (wird verdeckt abgefragt)')]
final class UserPasswordCommand extends Command
{
    public function __construct(private readonly PasswordReset $reset)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Benutzername');
        // Kein --password: Passwörter gehören nicht in die Shell-History oder die Prozessliste.
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getArgument('username');
        $helper = $this->getHelper('question');
        if (!is_string($username) || !$helper instanceof QuestionHelper) {
            return self::INVALID;
        }

        $question = (new Question('Neues Passwort (mind. 8 Zeichen): '))->setHidden(true)->setHiddenFallback(false);
        $password = $helper->ask($input, $output, $question);
        $again = $helper->ask($input, $output, (new Question('Passwort wiederholen: '))->setHidden(true)->setHiddenFallback(false));
        if (!is_string($password) || !is_string($again) || $password === '' || $password !== $again) {
            $output->writeln('<error>Die beiden Eingaben stimmen nicht überein oder sind leer. Es wurde nichts geändert.</error>');

            return self::INVALID;
        }

        try {
            $found = $this->reset->reset($username, $password);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . ' Es wurde nichts geändert.</error>');

            return self::INVALID;
        }
        if (!$found) {
            $output->writeln('<error>Benutzer nicht gefunden oder gelöscht. Es wurde nichts geändert.</error>');

            return self::FAILURE;
        }

        $output->writeln('Passwort für ' . $username . ' gesetzt. Ein Pflichtwechsel ist aufgehoben, alle Sitzungen dieses Benutzers sind beendet, die Sperre ist aufgehoben.');

        return self::SUCCESS;
    }
}
