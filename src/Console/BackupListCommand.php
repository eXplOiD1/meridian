<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Backup\BackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup:list', description: 'Listet die vorhandenen Sicherungen (neueste zuerst)')]
final class BackupListCommand extends Command
{
    public function __construct(private readonly BackupService $backups)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = $this->backups->list();
        if ($all === []) {
            $output->writeln('Keine Sicherungen vorhanden. Anlegen mit: backup:create');

            return self::SUCCESS;
        }
        foreach ($all as $info) {
            $output->writeln($info->name . '  ' . $info->createdAt->format('Y-m-d H:i:s') . ' UTC  ' . number_format($info->bytes / 1024, 0, ',', '.') . ' KiB');
        }

        return self::SUCCESS;
    }
}
