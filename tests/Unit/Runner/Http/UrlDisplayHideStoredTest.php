<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Runner\Http;

use Meridian\Runner\Http\UrlDisplay;
use Meridian\Runner\Http\UrlPolicy;
use Meridian\Settings\DisplayPathMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verschärfen gespeicherter Anzeige-URLs auf `hidden` ohne Entschlüsseln (docs/decisions/0003, E3): Ergebnis gleich
 * `fromParsed(…, Hidden)`, nie lockerer, auch bei unerwartetem Text.
 */
final class UrlDisplayHideStoredTest extends TestCase
{
    private const M = '••••';

    /**
     * @return array<string, array{0: string}>
     */
    public static function urls(): array
    {
        return [
            'Pfad und Query' => ['https://img.example.org/tools/api/cron.php?key=TEST-SECRET-do-not-use-1&mode=x'],
            'Kleinbuchstaben-Token' => ['https://hooks.example.org/hooks/abcdefghqrst'],
            'Token im Pfad' => ['https://api.example.org/bot123456:TEST-ABCdef_ghi/sendMessage'],
            'Wurzel' => ['https://example.org/'],
            'ohne Pfad' => ['https://example.org'],
            'nur Query' => ['http://example.org/?a=1'],
            'leere Query' => ['http://example.org/x?'],
            'Port' => ['https://example.org:8443/api/run'],
            'IPv6 mit Port' => ['http://[fd00::1]:8080/status?x=1'],
            'IPv4' => ['http://192.168.1.10/hook'],
        ];
    }

    #[DataProvider('urls')]
    public function testHidingTheAutoDisplayEqualsTheHiddenDisplay(string $url): void
    {
        $parsed = (new UrlPolicy())->parse($url);
        $auto = UrlDisplay::fromParsed($parsed, DisplayPathMode::Auto);
        $hidden = UrlDisplay::fromParsed($parsed, DisplayPathMode::Hidden);

        self::assertSame($hidden, UrlDisplay::hideStored($auto, $parsed->origin()));
        self::assertSame($hidden, UrlDisplay::hideStored($hidden, $parsed->origin()), 'idempotent');
    }

    public function testUnexpectedTextIsHiddenCompletely(): void
    {
        // Passt nicht zum Ursprung → Pfad und Query beide verborgen, nie etwas vom alten Text übernommen.
        self::assertSame('https://a.example/' . self::M . '?' . self::M, UrlDisplay::hideStored('https://b.example/secret', 'https://a.example'));
        self::assertSame('https://a.example/' . self::M . '?' . self::M, UrlDisplay::hideStored('https://a.example.evil/x', 'https://a.example'));
        self::assertSame('https://a.example/' . self::M . '?' . self::M, UrlDisplay::hideStored('https://a.example:9999/x', 'https://a.example'));
        self::assertSame('https://a.example/' . self::M . '?' . self::M, UrlDisplay::hideStored('', 'https://a.example'));
    }
}
