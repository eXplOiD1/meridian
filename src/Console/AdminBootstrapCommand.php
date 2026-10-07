<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\User\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Legt beim ersten Start den Admin aus Umgebungsvariablen an (Docker-Installation).
 *
 * MERIDIAN_ADMIN_USER                 Benutzername
 * MERIDIAN_ADMIN_PASSWORD             Passwort (oder besser:)
 * MERIDIAN_ADMIN_PASSWORD_FILE        Datei mit dem Passwort, z. B. ein Docker-Secret
 * MERIDIAN_ADMIN_NAME                 Anzeigename (optional, sonst der Benutzername)
 *
 * Idempotent: Sobald es einen Benutzer gibt, passiert nichts. Ein bestehendes
 * Passwort wird nie überschrieben, das Passwort wird nie ausgegeben.
 */
#[AsCommand(name: 'admin:bootstrap', description: 'Legt den ersten Admin aus MERIDIAN_ADMIN_* an, falls es noch keinen Benutzer gibt')]
final class AdminBootstrapCommand extends Command
{
    /**
     * @param array<string, string> $env
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly array $env,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = trim($this->env['MERIDIAN_ADMIN_USER'] ?? '');
        if ($username === '') {
            $output->writeln('MERIDIAN_ADMIN_USER nicht gesetzt, kein Admin wird angelegt.');

            return self::SUCCESS;
        }

        if ($this->users->hasUsers()) {
            $output->writeln('Es gibt bereits Benutzer, MERIDIAN_ADMIN_* wird ignoriert.');

            return self::SUCCESS;
        }

        $password = $this->readPassword($output);
        if ($password === null) {
            return self::FAILURE;
        }

        $displayName = trim($this->env['MERIDIAN_ADMIN_NAME'] ?? '');
        try {
            $id = $this->users->create($username, $displayName === '' ? $username : $displayName, $password, 'Admin');
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::INVALID;
        }

        $output->writeln('Admin "' . $username . '" angelegt (ID ' . $id . ').');

        return self::SUCCESS;
    }

    private function readPassword(OutputInterface $output): ?string
    {
        $file = $this->env['MERIDIAN_ADMIN_PASSWORD_FILE'] ?? '';
        if ($file !== '') {
            $content = is_readable($file) ? file_get_contents($file) : false;
            if ($content === false) {
                $output->writeln('<error>MERIDIAN_ADMIN_PASSWORD_FILE ist nicht lesbar.</error>');

                return null;
            }

            return rtrim($content, "\r\n");
        }

        $password = $this->env['MERIDIAN_ADMIN_PASSWORD'] ?? '';
        if ($password === '') {
            $output->writeln('<error>MERIDIAN_ADMIN_USER ist gesetzt, aber weder MERIDIAN_ADMIN_PASSWORD noch MERIDIAN_ADMIN_PASSWORD_FILE.</error>');

            return null;
        }

        return $password;
    }
}
