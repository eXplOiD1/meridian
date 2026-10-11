<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Security;

use Meridian\Security\AccessControl;
use Meridian\Security\AccessDenied;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;
use PHPUnit\Framework\TestCase;

final class AccessControlTest extends TestCase
{
    public function testNoGrantsMeansNoAccess(): void
    {
        self::assertFalse((new AccessControl())->can([], Permission::ViewJobs));
    }

    public function testUnrestrictedRoleAllowsEveryCategory(): void
    {
        $grants = [new RoleGrant('Operator', [Permission::ViewJobs, Permission::RunJobs])];

        self::assertTrue((new AccessControl())->can($grants, Permission::RunJobs, 'NAS'));
        self::assertFalse((new AccessControl())->can($grants, Permission::EditShellJobs, 'NAS'));
    }

    public function testCategoryRestriction(): void
    {
        $grants = [new RoleGrant('Operator', [Permission::RunJobs], ['Deuba24'])];
        $acl = new AccessControl();

        self::assertTrue($acl->can($grants, Permission::RunJobs, 'Deuba24'));
        self::assertFalse($acl->can($grants, Permission::RunJobs, 'NAS'));
        // Ohne Kategoriebezug gibt eine beschränkte Rolle nichts frei.
        self::assertFalse($acl->can($grants, Permission::RunJobs, null));
    }

    public function testRequireThrows(): void
    {
        $this->expectException(AccessDenied::class);
        (new AccessControl())->require([new RoleGrant('Beobachter', [Permission::ViewJobs])], Permission::ManageUsers);
    }
}
