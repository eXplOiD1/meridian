<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Runner\JobType;
use Meridian\Security\Permission;

/**
 * Welches Recht zum Ändern eines Jobs gehört: HTTP-Jobs `jobs.edit_http`, Shell-Jobs `jobs.edit_shell` (Phase 4).
 */
final class JobPermissions
{
    private function __construct()
    {
    }

    public static function edit(JobType $type): Permission
    {
        return match ($type) {
            JobType::Http => Permission::EditHttpJobs,
            JobType::Shell => Permission::EditShellJobs,
        };
    }
}
