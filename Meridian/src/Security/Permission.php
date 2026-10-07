<?php

declare(strict_types=1);

namespace Meridian\Security;

enum Permission: string
{
    case ViewJobs = 'jobs.view';
    case RunJobs = 'jobs.run';
    case EditHttpJobs = 'jobs.edit_http';
    case EditShellJobs = 'jobs.edit_shell';
    case EditStatusPages = 'status_pages.edit';
    case ManageUsers = 'users.manage';

    /**
     * Rechte, die über den Docker-Socket praktisch Root-Zugriff geben.
     * Werden in der Oberfläche besonders gekennzeichnet.
     */
    public function isDangerous(): bool
    {
        return $this === self::EditShellJobs || $this === self::ManageUsers;
    }
}
