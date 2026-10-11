<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Console;

use Meridian\Auth\AuditLog;
use Meridian\Console\SettingsGetCommand;
use Meridian\Console\SettingsSetCommand;
use Meridian\Job\HttpJobConfig;
use Meridian\Security\SecretMasker;
use Meridian\Settings\SettingsService;
use Meridian\Tests\Support\JobHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `settings:get` und `settings:set` (docs/decisions/0003, E10): dieselbe Prüfung, Transaktion und dasselbe Audit wie
 * die API, ohne Benutzer; Eingaben erscheinen nie in Meldungen.
 */
final class SettingsCommandTest extends TestCase
{
    use JobHarness;

    protected function setUp(): void
    {
        $this->setUpJobDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownJobDatabase();
    }

    private function service(): SettingsService
    {
        return new SettingsService($this->db(), new AuditLog($this->db(), $this->clock(), new SecretMasker()), $this->clock());
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function set(array $input): CommandTester
    {
        $tester = new CommandTester(new SettingsSetCommand($this->service()));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @param array<string, string> $input
     */
    private function get(array $input = []): CommandTester
    {
        $tester = new CommandTester(new SettingsGetCommand($this->service()));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->freshConnection()->fetchAll('SELECT key, value_json, updated_by FROM settings ORDER BY key');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function audit(): array
    {
        return $this->freshConnection()->fetchAll("SELECT user_id, target FROM audit_log WHERE action = 'settings.changed' ORDER BY id");
    }

    public function testGetListsEveryKeyWithDefaultAndSource(): void
    {
        $tester = $this->get();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(
            "http.max_timeout_seconds = 300 (Standard: 300; Quelle: Standard)\n"
            . "http.response_storage = off (Standard: off; Quelle: Standard)\n"
            . "http.display_path = hidden (Standard: hidden; Quelle: Standard)\nhttp.display_host = auto (Standard: auto; Quelle: Standard)\nshell.max_timeout_seconds = 3600 (Standard: 3600; Quelle: Standard)\njobs.reveal_for_edit = off (Standard: off; Quelle: Standard)\napp.public_url =  (Standard: ; Quelle: Standard)\nruns.retention_days = 30 (Standard: 30; Quelle: Standard)\njobs.min_interval_seconds = 10 (Standard: 10; Quelle: Standard)\nauth.session_days = -1 (Standard: -1; Quelle: Standard)\n",
            $tester->getDisplay(true),
        );
        self::assertSame([], $this->rows(), 'Lesen legt nichts an');
    }

    public function testSetWritesAuditsWithoutUserAndGetShowsIt(): void
    {
        $tester = $this->set(['key' => 'http.max_timeout_seconds', 'value' => '600']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("Einstellung http.max_timeout_seconds: 300 → 600.\n", $tester->getDisplay(true));
        self::assertSame([['key' => 'http.max_timeout_seconds', 'value_json' => '600', 'updated_by' => null]], $this->rows());
        self::assertSame([['user_id' => null, 'target' => 'http.max_timeout_seconds: 300 → 600']], $this->audit());
        self::assertSame("http.max_timeout_seconds = 600 (Standard: 300; Quelle: gespeichert)\n", $this->get(['key' => 'http.max_timeout_seconds'])->getDisplay(true));
    }

    public function testBoundariesAndReset(): void
    {
        self::assertSame(Command::SUCCESS, $this->set(['key' => 'http.max_timeout_seconds', 'value' => '1'])->getStatusCode());
        self::assertSame(Command::SUCCESS, $this->set(['key' => 'http.max_timeout_seconds', 'value' => '3600'])->getStatusCode());

        $reset = $this->set(['key' => 'http.max_timeout_seconds', '--reset' => true]);

        self::assertSame(Command::SUCCESS, $reset->getStatusCode());
        self::assertSame("Einstellung http.max_timeout_seconds: 3600 → 300 (Standard).\n", $reset->getDisplay(true));
        self::assertSame([], $this->rows());
        self::assertSame('http.max_timeout_seconds: 3600 → 300 (Standard)', $this->audit()[2]['target'] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidValues(): array
    {
        return [
            'Zeitlimit 0' => ['http.max_timeout_seconds', '0'],
            'Zeitlimit 3601' => ['http.max_timeout_seconds', '3601'],
            'Zeitlimit führende Null' => ['http.max_timeout_seconds', '0300'],
            'Zeitlimit Kommazahl' => ['http.max_timeout_seconds', '300.5'],
            'Zeitlimit negativ' => ['http.max_timeout_seconds', '-5'],
            'Zeitlimit riesig' => ['http.max_timeout_seconds', '99999999999999999999'],
            'Speichern maybe' => ['http.response_storage', 'maybe'],
            'Speichern groß' => ['http.response_storage', 'ON'],
            'Pfad inherit' => ['http.display_path', 'inherit'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejectedLikeTheApi(string $key, string $value): void
    {
        $tester = $this->set(['key' => $key, 'value' => $value]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame([], $this->rows());
        self::assertSame([], $this->audit());
    }

    public function testUnknownKeyAndAmbiguousArgumentsAreRejected(): void
    {
        $unknown = $this->set(['key' => 'settings.manage', 'value' => '1']);
        self::assertSame(Command::INVALID, $unknown->getStatusCode());
        self::assertStringContainsString('Einstellung unbekannt. Erlaubt sind http.max_timeout_seconds, http.response_storage, http.display_path,', $unknown->getDisplay(true));
        self::assertSame(Command::INVALID, $this->get(['key' => 'http.nope'])->getStatusCode());

        self::assertSame(Command::INVALID, $this->set(['key' => 'http.display_path'])->getStatusCode(), 'weder Wert noch --reset');
        self::assertSame(Command::INVALID, $this->set(['key' => 'http.display_path', 'value' => 'hidden', '--reset' => true])->getStatusCode(), 'beides');
        self::assertSame([], $this->rows());
        self::assertSame([], $this->audit());
    }

    public function testHiddenTightensStoredDisplayUrlsAndAutoLoosensNothing(): void
    {
        $this->settings()->set(\Meridian\Settings\SettingKey::HttpDisplayPath, 'auto', 1);
        $id = $this->jobs()->insert($this->draft(self::input(['request' => ['url' => 'https://img.example.org/tools/api/cron.php?key=' . self::secret('cli')]])), 1);

        $hidden = $this->set(['key' => 'http.display_path', 'value' => 'hidden']);

        self::assertSame(Command::SUCCESS, $hidden->getStatusCode());
        self::assertStringContainsString('Anzeige-URL bei 1 HTTP-Job(s) verschärft', $hidden->getDisplay(true));
        self::assertSame('https://img.example.org/••••?••••', $this->displayUrl($id));

        self::assertSame(Command::SUCCESS, $this->set(['key' => 'http.display_path', 'value' => 'auto'])->getStatusCode());
        self::assertSame('https://img.example.org/••••?••••', $this->displayUrl($id), 'zurück auf auto lockert nichts');
        self::assertSame([
            ['user_id' => null, 'target' => 'http.display_path: auto → hidden; Anzeige-URL bei 1 Job(s) verschärft'],
            ['user_id' => null, 'target' => 'http.display_path: hidden → auto'],
        ], $this->audit());
    }

    public function testInputNeverAppearsInOutput(): void
    {
        $secret = self::secret('cli');
        foreach (['http.max_timeout_seconds', 'http.response_storage', 'http.display_path', $secret] as $key) {
            $tester = $this->set(['key' => $key, 'value' => $secret]);
            self::assertSame(Command::INVALID, $tester->getStatusCode());
            self::assertStringNotContainsString($secret, $tester->getDisplay(true));
        }
        self::assertStringNotContainsString($secret, $this->get(['key' => $secret])->getDisplay(true));
        self::assertStringNotContainsString($secret, (string) json_encode($this->freshConnection()->fetchAll('SELECT * FROM audit_log')));
    }

    private function displayUrl(int $jobId): string
    {
        $json = $this->freshConnection()->fetchOne('SELECT config_json FROM jobs WHERE id = :id', ['id' => $jobId])['config_json'] ?? null;
        self::assertIsString($json);

        return HttpJobConfig::fromJson($json)->displayUrl;
    }
}
