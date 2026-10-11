<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Security;

use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;
use PHPUnit\Framework\TestCase;

final class RoleGrantTest extends TestCase
{
    public function testDangerousPermissionsAreNeverGrantedToARestrictedRole(): void
    {
        $restricted = new RoleGrant('Admin', [Permission::EditShellJobs, Permission::ManageUsers, Permission::EditHttpJobs], ['A']);

        self::assertFalse($restricted->allows(Permission::EditShellJobs, 'A'), 'Shell-Jobs in der eigenen Kategorie');
        self::assertFalse($restricted->allows(Permission::ManageUsers, null));
        self::assertFalse($restricted->allows(Permission::ManageUsers, 'A'));
        self::assertTrue($restricted->allows(Permission::EditHttpJobs, 'A'), 'gewöhnliche Rechte gelten in der Kategorie');
        self::assertFalse($restricted->allows(Permission::EditHttpJobs, 'B'));
    }

    public function testDangerousPermissionsWorkWhenExplicitlyUnrestricted(): void
    {
        $all = new RoleGrant('Admin', [Permission::EditShellJobs, Permission::ManageUsers], null);

        self::assertTrue($all->allows(Permission::EditShellJobs, 'A'));
        self::assertTrue($all->allows(Permission::ManageUsers, null));
    }

    public function testAnEmptyCategoryListAllowsNothing(): void
    {
        $none = new RoleGrant('Operator', [Permission::ViewJobs, Permission::RunJobs], []);

        self::assertFalse($none->allows(Permission::ViewJobs, 'A'));
        self::assertFalse($none->allows(Permission::ViewJobs, null));
    }
}
