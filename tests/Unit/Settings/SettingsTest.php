<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Settings;

use Meridian\Settings\DisplayPathMode;
use Meridian\Settings\InvalidSetting;
use Meridian\Settings\ResponseStorage;
use Meridian\Settings\SettingKey;
use Meridian\Settings\Settings;
use Meridian\Tests\Support\JobHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
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

    public function testMissingRowsGiveTheDefaultsWithoutSeedingAnything(): void
    {
        $settings = $this->settings();

        self::assertSame(300, $settings->maxTimeoutSeconds());
        self::assertSame(ResponseStorage::Off, $settings->responseStorage());
        self::assertSame(DisplayPathMode::Hidden, $settings->displayPath());
        $count = $this->db()->fetchOne('SELECT COUNT(*) AS n FROM settings');
        self::assertSame(0, $count['n'] ?? null, 'Lesen legt nie eine Zeile an');
    }

    public function testSetRoundtripThroughANewConnectionAndReset(): void
    {
        $settings = $this->settings();
        $settings->set(SettingKey::HttpMaxTimeout, 600, 1);
        $settings->set(SettingKey::HttpResponseStorage, 'never', 1);
        $settings->set(SettingKey::HttpDisplayPath, 'hidden', 1);

        $fresh = new Settings($this->freshConnection(), $this->clock());
        self::assertSame(600, $fresh->maxTimeoutSeconds());
        self::assertSame(ResponseStorage::Never, $fresh->responseStorage());
        self::assertSame(DisplayPathMode::Hidden, $fresh->displayPath());
        $row = $this->freshConnection()->fetchOne('SELECT value_json, updated_by, updated_at FROM settings WHERE key = :k', ['k' => 'http.max_timeout_seconds']);
        self::assertSame(['value_json' => '600', 'updated_by' => 1, 'updated_at' => '2026-10-07T08:00:00+00:00'], $row);

        $settings->set(SettingKey::HttpMaxTimeout, 700, 7);
        $row = $this->freshConnection()->fetchOne('SELECT value_json, updated_by FROM settings WHERE key = :k', ['k' => 'http.max_timeout_seconds']);
        self::assertSame(['value_json' => '700', 'updated_by' => 7], $row, 'zweites Setzen ersetzt die Zeile');

        $settings->set(SettingKey::HttpMaxTimeout, null, 1);
        self::assertSame(300, $settings->maxTimeoutSeconds(), 'null setzt auf den Standard zurück');
        $count = $this->db()->fetchOne("SELECT COUNT(*) AS n FROM settings WHERE key = 'http.max_timeout_seconds'");
        self::assertSame(0, $count['n'] ?? null, 'Zeile gelöscht');
    }

    /**
     * @return array<string, array{SettingKey, mixed}>
     */
    public static function invalidValues(): array
    {
        return [
            'Zeitlimit 0' => [SettingKey::HttpMaxTimeout, 0],
            'Zeitlimit 3601' => [SettingKey::HttpMaxTimeout, 3601],
            'Zeitlimit als Text' => [SettingKey::HttpMaxTimeout, '300'],
            'Zeitlimit Kommazahl' => [SettingKey::HttpMaxTimeout, 300.5],
            'Zeitlimit bool' => [SettingKey::HttpMaxTimeout, true],
            'Speichern maybe' => [SettingKey::HttpResponseStorage, 'maybe'],
            'Speichern Zahl' => [SettingKey::HttpResponseStorage, 1],
            'Speichern groß' => [SettingKey::HttpResponseStorage, 'ON'],
            'Pfad inherit' => [SettingKey::HttpDisplayPath, 'inherit'],
            'Pfad Liste' => [SettingKey::HttpDisplayPath, ['auto']],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsWrongTypeAndRangeAndStoresNothing(SettingKey $key, mixed $value): void
    {
        try {
            $this->settings()->set($key, $value, 1);
            self::fail('Muss abgelehnt werden.');
        } catch (InvalidSetting $e) {
            self::assertNotSame('', $e->getMessage());
        }
        $count = $this->db()->fetchOne('SELECT COUNT(*) AS n FROM settings');
        self::assertSame(0, $count['n'] ?? null);
    }

    public function testBrokenValuesInTheDatabaseFallBackToTheDefaultAndLogWithoutTheValue(): void
    {
        $broken = self::secret('broken');
        foreach ([
            ['http.max_timeout_seconds', '"' . $broken . '"'],
            ['http.response_storage', '"' . $broken . '"'],
            ['http.display_path', '99999'],
        ] as [$key, $json]) {
            $this->db()->execute('INSERT INTO settings (key, value_json, updated_at) VALUES (:k, :v, :t)', ['k' => $key, 'v' => $json, 't' => '2026-10-07T08:00:00+00:00']);
        }
        $log = ini_get('error_log');
        $file = tempnam(sys_get_temp_dir(), 'meridian-log');
        self::assertIsString($file);
        ini_set('error_log', $file);
        try {
            $settings = $this->settings();
            self::assertSame(300, $settings->maxTimeoutSeconds());
            self::assertSame(ResponseStorage::Off, $settings->responseStorage());
            self::assertSame(DisplayPathMode::Hidden, $settings->displayPath());
            $written = (string) file_get_contents($file);
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
            unlink($file);
        }

        self::assertStringContainsString('http.max_timeout_seconds', $written);
        self::assertStringContainsString('Standardwert', $written);
        self::assertStringNotContainsString($broken, $written, 'Fehlerlog ohne Wert');
        self::assertStringNotContainsString('99999', $written);
    }

    public function testOutOfRangeValueInTheDatabaseGivesTheDefault(): void
    {
        $this->db()->execute("INSERT INTO settings (key, value_json, updated_at) VALUES ('http.max_timeout_seconds', '999999', '2026-10-07T08:00:00+00:00')");
        $log = ini_get('error_log');
        $file = tempnam(sys_get_temp_dir(), 'meridian-log');
        self::assertIsString($file);
        ini_set('error_log', $file);
        try {
            self::assertSame(300, $this->settings()->maxTimeoutSeconds());
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
            unlink($file);
        }
    }

    public function testTheKeyAllowlistInCodeMatchesTheDatabaseCheck(): void
    {
        foreach (SettingKey::cases() as $key) {
            $this->settings()->set($key, $key->default(), 1);
        }
        $count = $this->db()->fetchOne('SELECT COUNT(*) AS n FROM settings');
        self::assertSame(count(SettingKey::cases()), $count['n'] ?? null, 'jeder Schlüssel im Code ist auch im CHECK erlaubt');

        $this->expectException(\PDOException::class);
        $this->db()->execute("INSERT INTO settings (key, value_json, updated_at) VALUES ('http.unknown', '1', '2026-10-07T08:00:00+00:00')");
    }
}
