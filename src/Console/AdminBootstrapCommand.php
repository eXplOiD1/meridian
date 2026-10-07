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
 * MERIDIAN_ADMIN_USER                 Benutzername (Standard: admin)
 * MERIDIAN_ADMIN_PASSWORD             Passwort (oder besser:)
 * MERIDIAN_ADMIN_PASSWORD_FILE        Datei mit dem Passwort, z. B. ein Docker-Secret
 * MERIDIAN_ADMIN_NAME                 Anzeigename (optional, sonst der Benutzername)
 *
 * Ist kein Passwort gesetzt, wird ein zufälliges erzeugt und genau einmal ausgegeben,
 * damit die Installation ohne manuelle Vorbereitung nutzbar ist.
 *
 * Idempotent: Sobald es einen Benutzer gibt, passiert nichts. Ein bestehendes
 * Passwort wird nie überschrieben; ein selbst gesetztes Passwort wird nie ausgegeben.
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
            $username = 'admin';
        }

        if ($this->users->hasUsers()) {
            $output->writeln('Es gibt bereits Benutzer, MERIDIAN_ADMIN_* wird ignoriert.');

            return self::SUCCESS;
        }

        $generated = false;
        $password = $this->readPassword($output);
        if ($password === null) {
            return self::FAILURE;
        }
        if ($password === '') {
            $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
            $generated = true;
        }

        $displayName = trim($this->env['MERIDIAN_ADMIN_NAME'] ?? '');
        try {
            $id = $this->users->create($username, $displayName === '' ? $username : $displayName, $password, 'Admin');
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::INVALID;
        }

        $output->writeln('Admin "' . $username . '" angelegt (ID ' . $id . ').');
        if ($generated) {
            $output->writeln('Zufälliges Passwort (wird nur jetzt angezeigt, bitte nach dem Login ändern): ' . $password);
        }

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

        // Leer heißt: es wird ein Zufallspasswort erzeugt.
        return $this->env['MERIDIAN_ADMIN_PASSWORD'] ?? '';
    }
}
