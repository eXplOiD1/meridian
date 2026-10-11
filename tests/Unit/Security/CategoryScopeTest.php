<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Security;

use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Security\AccessControl;
use Meridian\Security\CategoryScope;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;
use PHPUnit\Framework\TestCase;

/**
 * AccessControl::scope() ist die eine Sichtbarkeitsfunktion: sie muss für jede Kategorie (auch „ohne“)
 * exakt dasselbe sagen wie can().
 */
final class CategoryScopeTest extends TestCase
{
    private const CATEGORIES = ['A', 'B', 'C'];

    /** Kategorien, gegen die geprüft wird: bekannte, eine fremde und „ohne Kategorie“. */
    private const PROBES = ['A', 'B', 'C', 'D', null];

    public function testScopeEqualsCanForRandomGrantCombinations(): void
    {
        $acl = new AccessControl();
        mt_srand(20261007);
        for ($i = 0; $i < 3000; $i++) {
            $grants = $this->randomGrants();
            foreach (Permission::cases() as $permission) {
                $scope = $acl->scope($grants, $permission);
                foreach (self::PROBES as $category) {
                    self::assertSame(
                        $acl->can($grants, $permission, $category),
                        $scope->contains($category),
                        sprintf('Durchlauf %d, Recht %s, Kategorie %s', $i, $permission->value, $category ?? 'ohne'),
                    );
                }
            }
        }
    }

    public function testScopeEqualsCanForEveryStandardRoleShape(): void
    {
        $acl = new AccessControl();
        $roles = [
            'Admin' => Permission::cases(),
            'Operator' => [Permission::ViewJobs, Permission::RunJobs, Permission::EditHttpJobs, Permission::EditStatusPages],
            'Beobachter' => [Permission::ViewJobs],
        ];
        $restrictions = [null, [], ['A'], ['A', 'B'], ['B']];
        foreach ($roles as $role => $permissions) {
            foreach ($restrictions as $categories) {
                $grants = [new RoleGrant($role, $permissions, $categories)];
                foreach (Permission::cases() as $permission) {
                    $scope = $acl->scope($grants, $permission);
                    foreach (self::PROBES as $category) {
                        self::assertSame($acl->can($grants, $permission, $category), $scope->contains($category));
                    }
                }
            }
        }
    }

    public function testEmptyCategoryListIsNothingNeverEverything(): void
    {
        $acl = new AccessControl();
        $scope = $acl->scope([new RoleGrant('Operator', [Permission::ViewJobs], [])], Permission::ViewJobs);

        self::assertTrue($scope->isEmpty());
        self::assertFalse($scope->isAll());
        self::assertFalse($scope->contains(null));
        self::assertFalse($scope->contains('A'));
        self::assertTrue($acl->scope([], Permission::ViewJobs)->isEmpty(), 'ohne Zuweisung: nichts');
    }

    public function testRestrictedRolesNeverReachDangerousPermissions(): void
    {
        $acl = new AccessControl();
        $grants = [new RoleGrant('Admin', Permission::cases(), ['A', 'B'])];
        foreach (Permission::cases() as $permission) {
            if ($permission->isDangerous()) {
                self::assertTrue($acl->scope($grants, $permission)->isEmpty(), $permission->value);
            }
        }
        self::assertTrue(Permission::ManageInternalTargets->isDangerous());
        self::assertTrue(Permission::ManageSettings->isDangerous());
        self::assertSame(['A', 'B'], $acl->scope($grants, Permission::ViewJobs)->names());
    }

    public function testUnrestrictedGrantIsEverythingAndUnionOfRestrictedGrants(): void
    {
        $acl = new AccessControl();
        $all = $acl->scope([new RoleGrant('Operator', [Permission::RunJobs], ['A']), new RoleGrant('Beobachter', [Permission::RunJobs])], Permission::RunJobs);
        self::assertTrue($all->isAll());
        self::assertSame([], $all->names());
        self::assertTrue($all->contains(null));

        $union = $acl->scope([
            new RoleGrant('Operator', [Permission::RunJobs], ['B', 'A']),
            new RoleGrant('Beobachter', [Permission::ViewJobs]),
            new RoleGrant('Operator', [Permission::RunJobs], ['A', 'C']),
        ], Permission::RunJobs);
        self::assertSame(['A', 'B', 'C'], $union->names(), 'sortiert, ohne Doppelte; Beobachter ohne run zählt nicht');
        self::assertFalse($union->contains(null));
    }

    public function testSqlPredicateFromTheDecisionMatchesTheScope(): void
    {
        $db = Connection::inMemory();
        (new Migrator($db, dirname(__DIR__, 3) . '/migrations'))->migrate();
        $db->execute("INSERT INTO categories (id, name) VALUES (1, 'A'), (2, 'B'), (3, 'O''Neil \"x\"')");
        foreach ([['a', 1], ['b', 2], ['q', 3], ['ohne', null]] as [$name, $category]) {
            $db->execute(
                "INSERT INTO jobs (name, type, category_id, cron, payload_enc, created_at, updated_at) VALUES (:n, 'http', :c, '* * * * *', 'v1:unecht', 'x', 'x')",
                ['n' => $name, 'c' => $category],
            );
        }
        $visible = static fn (CategoryScope $scope): array => array_column($db->fetchAll(
            'SELECT j.name FROM jobs j LEFT JOIN categories c ON c.id = j.category_id
              WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names))) ORDER BY j.name',
            $scope->sqlParameters(),
        ), 'name');

        self::assertSame(['a', 'b', 'ohne', 'q'], $visible(CategoryScope::everything()));
        self::assertSame(['a'], $visible(CategoryScope::only(['A'])));
        self::assertSame(['q'], $visible(CategoryScope::only(['O\'Neil "x"'])), 'Sonderzeichen über JSON, nicht über SQL');
        self::assertSame([], $visible(CategoryScope::nothing()));
        self::assertSame([], $visible(CategoryScope::only([])));
    }

    /**
     * @return list<RoleGrant>
     */
    private function randomGrants(): array
    {
        $grants = [];
        $count = mt_rand(0, 3);
        for ($g = 0; $g < $count; $g++) {
            $permissions = array_values(array_filter(Permission::cases(), static fn (): bool => mt_rand(0, 1) === 1));
            $categories = null;
            if (mt_rand(0, 2) > 0) {
                $categories = array_values(array_filter(self::CATEGORIES, static fn (): bool => mt_rand(0, 1) === 1));
            }
            $grants[] = new RoleGrant('R' . $g, $permissions, $categories);
        }

        return $grants;
    }
}
