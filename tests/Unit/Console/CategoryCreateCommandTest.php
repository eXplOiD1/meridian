<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Console;

use Meridian\Auth\AuditLog;
use Meridian\Console\CategoryCreateCommand;
use Meridian\Job\CategoryName;
use Meridian\Job\CategoryRepository;
use Meridian\Security\SecretMasker;
use Meridian\Tests\Support\JobApiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CategoryCreateCommandTest extends TestCase
{
    use JobApiHarness;

    private function create(string $name): CommandTester
    {
        $command = new CategoryCreateCommand(new \Meridian\Category\CategoryService($this->db(), new AuditLog($this->db(), $this->clock(), new SecretMasker()), $this->clock()));
        $tester = new CommandTester($command);
        $tester->execute(['name' => $name]);

        return $tester;
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        $names = [];
        foreach ($this->db()->fetchAll('SELECT name FROM categories ORDER BY id') as $row) {
            $names[] = is_string($row['name'] ?? null) ? $row['name'] : '';
        }

        return $names;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validNames(): array
    {
        return [
            'einfach' => ['Deuba24'],
            'mit Leerzeichen' => ['Heim Netz'],
            'Sonderzeichen' => ['nas_1.backup-2'],
            'ein Zeichen' => ['A'],
            '64 Zeichen' => [str_repeat('x', 64)],
        ];
    }

    #[DataProvider('validNames')]
    public function testCreatesTheCategoryAndAuditsItWithoutUser(string $name): void
    {
        $tester = $this->create($name);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([$name], $this->names(), 'Roundtrip: der Name steht genau so in der Tabelle');
        $id = self::dig($this->db()->fetchOne('SELECT id FROM categories'), 'id');
        self::assertIsInt($id);
        self::assertStringContainsString('ID ' . $id, $tester->getDisplay(true));
        self::assertSame(
            [['user_id' => null, 'action' => 'category.created', 'target' => 'category:' . $id . ' ' . $name]],
            $this->db()->fetchAll('SELECT user_id, action, target FROM audit_log'),
        );
        self::assertTrue(CategoryName::isValid($name));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'leer' => [''],
            '65 Zeichen' => [str_repeat('x', 65)],
            'führendes Leerzeichen' => [' NAS'],
            'nur Leerzeichen' => ['   '],
            'abschließendes Leerzeichen' => ['NAS '],
            'führender Punkt' => ['.NAS'],
            'führender Bindestrich' => ['-NAS'],
            'Umlaut' => ['Büro'],
            'Schrägstrich' => ['a/b'],
            'Anführungszeichen' => ["a'b"],
            'SQL' => ["x'); DROP TABLE categories;--"],
            'HTML' => ['<script>alert(1)</script>'],
            'Zeilenumbruch' => ["NAS\nBackup"],
            'Zeilenumbruch am Ende' => ["NAS\n"],
            'Nullbyte' => ["NAS\0"],
            'Tabulator' => ["NAS\tBackup"],
            'Prozent' => ['%'],
            'JSON' => ['["A"]'],
            'Steuerzeichen' => ["a\x1b[31m"],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidNamesWithoutWritingAnything(string $name): void
    {
        $tester = $this->create($name);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame([], $this->names());
        self::assertSame([], $this->db()->fetchAll('SELECT * FROM audit_log'), 'ohne Anlegen kein Audit-Eintrag');
        self::assertStringContainsString('Ungültiger Name', $tester->getDisplay(true));
        self::assertFalse(CategoryName::isValid($name));
    }

    public function testRejectedInputIsNeverEchoed(): void
    {
        $secret = self::testSecret('kategorie');

        $tester = $this->create($secret . '/' . str_repeat('x', 100));

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringNotContainsString($secret, $tester->getDisplay(true));
    }

    public function testDuplicateNameIsRefusedAlsoInOtherCase(): void
    {
        self::assertSame(Command::SUCCESS, $this->create('Deuba24')->getStatusCode());

        foreach (['Deuba24', 'deuba24', 'DEUBA24'] as $again) {
            $tester = $this->create($again);
            self::assertSame(Command::FAILURE, $tester->getStatusCode(), $again);
            self::assertStringContainsString('gibt es schon', $tester->getDisplay(true));
        }
        self::assertSame(['Deuba24'], $this->names(), 'kein Duplikat');
        self::assertCount(1, $this->db()->fetchAll("SELECT id FROM audit_log WHERE action = 'category.created'"), 'nur das erfolgreiche Anlegen wird protokolliert');
    }

    public function testNoNameStaysUnchangedWhenTheNameWasInvalid(): void
    {
        $this->create('NAS');
        $this->create('NAS!');

        self::assertSame(['NAS'], $this->names());
    }

    // --- Eine neue oder gelöschte Kategorie macht aus „beschränkt“ nie „alle“ -----------------------------------------------

    public function testANewCategoryDoesNotWidenARoleRestrictedToCategories(): void
    {
        $this->seedJobs();
        $this->as('operatorA');
        $this->create('Neu');

        $visible = $this->json($this->api('operatorA', 'GET', '/api/categories'));
        self::assertSame([['id' => 1, 'name' => 'A']], $visible['categories']);
        $all = $this->json($this->api('operator', 'GET', '/api/categories'));
        self::assertSame(['A', 'B', 'Neu'], array_column((array) $all['categories'], 'name'), 'wer „alle“ hat, sieht sie');
        $roleGrants = $this->db()->fetchAll('SELECT user_role_id, category_id FROM user_role_categories');
        self::assertCount(1, $roleGrants, 'keine Zuweisung entsteht von selbst');
    }

    public function testDeletedCategoryListMeansNothingNeverEverything(): void
    {
        $this->seedJobs();
        self::assertSame(200, $this->api('operatorA', 'GET', '/api/jobs')->getStatusCode());
        $this->create('Neu');
        $this->db()->execute('DELETE FROM categories WHERE id = 1'); // die einzige Kategorie von operatorA

        $jobs = $this->api('operatorA', 'GET', '/api/jobs');
        self::assertSame(403, $jobs->getStatusCode(), 'leere Liste = kein Recht, nicht alle Jobs');
        self::assertSame([], $this->json($this->api('operatorA', 'GET', '/api/categories'))['categories']);
        self::assertSame(404, $this->api('operatorA', 'GET', '/api/jobs/' . $this->jobIds['JB'])->getStatusCode());
        self::assertSame(404, $this->api('operatorA', 'POST', '/api/jobs/' . $this->jobIds['JN'] . '/run')->getStatusCode());
    }
}
