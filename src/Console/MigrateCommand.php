<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Database\Migrator;
use Meridian\Job\DisplayUrlUpgrade;
use Meridian\User\AdminInvariant;
use Meridian\User\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate', description: 'Spielt ausstehende Datenbank-Migrationen ein')]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly ?DisplayUrlUpgrade $displayUrls = null,
        private readonly ?AdminInvariant $invariant = null,
        private readonly ?UserRepository $users = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $applied = $this->migrator->migrate();
        // Nach dem Schema: gespeicherte Anzeige-URLs auf die aktuelle Regel verschärfen (ohne Entschlüsseln).
        $tightened = $this->displayUrls?->run() ?? 0;
        if ($tightened > 0) {
            $output->writeln('Anzeige-URL bei ' . $tightened . ' Job(s) auf die aktuelle Regel verschärft.');
        }
        if ($applied === []) {
            $output->writeln('Datenbank ist aktuell.');
        }
        foreach ($applied as $name) {
            $output->writeln('Eingespielt: ' . $name);
        }
        $this->warnWithoutAdmin($output);

        return self::SUCCESS;
    }

    /**
     * Gibt es Benutzer, aber keinen mit allen Rechten (z. B. nach einem neuen Recht nur eigene Rollen mit „allen“
     * Rechten, ADR 0006 E2), blockiert `AdminInvariant` jede Rechteänderung. Nur warnen, nichts ändern.
     */
    private function warnWithoutAdmin(OutputInterface $output): void
    {
        if ($this->invariant === null || $this->users === null || !$this->users->hasUsers() || $this->invariant->countAdmins() > 0) {
            return;
        }
        $output->writeln('<comment>Warnung: Kein aktiver Administrator mit allen Rechten. Einen anlegen mit '
            . '`bin/meridian user:create <name> --role=Admin` (oder einem Benutzer die Rolle Admin für alle Kategorien zuweisen).</comment>');
    }
}
