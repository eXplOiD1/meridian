<?php

declare(strict_types=1);

namespace Meridian\Tests\Support;

use Meridian\Auth\AuditLog;
use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Job\CategoryRepository;
use Meridian\Job\JobDraft;
use Meridian\Job\JobRepository;
use Meridian\Job\JobValidator;
use Meridian\Runner\Http\AddressPolicy;
use Meridian\Runner\Http\DbInternalTargetSource;
use Meridian\Security\CategoryScope;
use Meridian\Security\SecretBox;
use Meridian\Security\SecretMasker;
use Meridian\Settings\Settings;
use PHPUnit\Framework\Assert;

/**
 * Gemeinsame Hilfen für Tests der Job-Schicht: Dateidatenbank (damit eine zweite Verbindung zurückliest),
 * Kategorien A und B, Schlüssel je Test. Test-Geheimnisse werden zur Laufzeit zusammengesetzt, damit sie
 * nirgends als Literal im Repository stehen.
 */
trait JobHarness
{
    private string $dbFile = '';
    private ?Connection $dbInstance = null;
    private ?SecretBox $boxInstance = null;
    private ?MutableClock $clockInstance = null;

    private function setUpJobDatabase(): void
    {
        $this->dbFile = SchedulerHarness::tempDatabase();
        $db = $this->db();
        (new Migrator($db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $db->execute("INSERT INTO categories (id, name) VALUES (1, 'A'), (2, 'B')");
        $db->execute("INSERT INTO users (id, username, display_name, password_hash, created_at) VALUES (1, 'alex', 'Alex', 'x', '2026-10-07T00:00:00+00:00'), (7, 'bea', 'Bea', 'x', '2026-10-07T00:00:00+00:00')");
    }

    private function tearDownJobDatabase(): void
    {
        $this->dbInstance = null;
        SchedulerHarness::removeDatabase($this->dbFile);
    }

    private function db(): Connection
    {
        return $this->dbInstance ??= Connection::open($this->dbFile);
    }

    private function freshConnection(): Connection
    {
        return Connection::open($this->dbFile);
    }

    private function box(): SecretBox
    {
        return $this->boxInstance ??= new SecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    private function clock(): MutableClock
    {
        return $this->clockInstance ??= new MutableClock();
    }

    private function settings(): Settings
    {
        return new Settings($this->db(), $this->clock());
    }

    private function jobs(?Connection $db = null): JobRepository
    {
        return new JobRepository($db ?? $this->db(), $this->box(), $this->clock());
    }

    private function audit(): AuditLog
    {
        return new AuditLog($this->db(), $this->clock(), new SecretMasker());
    }

    private function validator(): JobValidator
    {
        return new JobValidator(
            new CategoryRepository($this->db()),
            $this->settings(),
            $this->clock(),
            new AddressPolicy(new DbInternalTargetSource($this->db())),
        );
    }

    /**
     * Test-Geheimnis aus Teilen zusammengesetzt (kein Literal im Quelltext).
     */
    private static function secret(string $part): string
    {
        return 'TEST-SECRET-do-not-use-' . $part . '-' . strrev($part) . '9';
    }

    /**
     * Gültige Eingabe für einen neuen HTTP-Job; `$override` ersetzt Felder der obersten Ebene.
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function input(array $override = []): array
    {
        return $override + [
            'name' => 'Hub',
            'type' => 'http',
            'category_id' => 1,
            'cron' => '*/5 * * * *',
            'timezone' => 'Europe/Berlin',
            'request' => ['url' => 'https://img.example.org/tools/api/cron.php?key=' . self::secret('query')],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function draft(array $input, ?CategoryScope $scope = null): JobDraft
    {
        return $this->validator()->validate($input, $scope ?? CategoryScope::everything(), null);
    }

    private static function text(mixed $value): string
    {
        Assert::assertIsString($value);

        return $value;
    }

    private static function intValue(mixed $value): int
    {
        Assert::assertIsInt($value);

        return $value;
    }
}
