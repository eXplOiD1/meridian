<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Auth\AuditLog;
use Meridian\Auth\LoginThrottle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Notausgang: hebt die Sperre nach Fehlversuchen für einen Benutzernamen oder eine IP auf, auch wenn
 * sich niemand mehr anmelden kann. Die Vertrauensgrenze ist der Betriebssystem-Benutzer.
 */
#[AsCommand(name: 'auth:unlock', description: 'Hebt die Sperre nach Fehlversuchen für einen Benutzernamen oder eine IP auf')]
final class UnlockCommand extends Command
{
    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly AuditLog $audit,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Benutzername oder IP-Adresse');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getArgument('target');
        if (!is_string($target)) {
            return self::INVALID;
        }

        if (filter_var($target, FILTER_VALIDATE_IP) !== false) {
            $this->throttle->unlockIp($target);
            $this->audit->record(null, 'auth.unlocked', 'ip:' . $target);
        } elseif (preg_match('/^[a-z0-9._-]{2,64}$/i', $target) === 1) {
            $this->throttle->unlockUser($target);
            $this->audit->record(null, 'auth.unlocked', $target);
        } else {
            $output->writeln('<error>Weder eine gültige IP-Adresse noch ein Benutzername (2 bis 64 Zeichen: Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich).</error>');

            return self::INVALID;
        }

        $output->writeln('Sperre für ' . $target . ' aufgehoben.');

        return self::SUCCESS;
    }
}
