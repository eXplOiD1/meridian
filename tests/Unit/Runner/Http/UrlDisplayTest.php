<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Runner\Http;

use Meridian\Runner\Http\UrlDisplay;
use Meridian\Runner\Http\UrlPolicy;
use Meridian\Settings\DisplayPathMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pflicht-Leak-Tests für die Anzeige-URL (docs/decisions/0003, E2). Alle Test-Geheimnisse sind offensichtlich
 * unecht; Slack-artige URLs werden zur Laufzeit aus Teilen zusammengesetzt (gitleaks, Push-Protection).
 */
final class UrlDisplayTest extends TestCase
{
    private const M = '••••';

    /**
     * @return iterable<string, array{0: string, 1: DisplayPathMode, 2: string, 3: list<string>}>
     *         URL, Modus, erwartete Anzeige, geheime Teile
     */
    public static function cases(): iterable
    {
        $auto = DisplayPathMode::Auto;
        $hidden = DisplayPathMode::Hidden;

        yield 'Telegram' => ['https://api.telegram.org/bot123456:TEST-ABCdef_ghi/sendMessage', $auto, 'https://api.telegram.org/' . self::M . '/' . self::M, ['bot123456:TEST-ABCdef_ghi', '123456', 'TEST-ABCdef_ghi', 'sendMessage']];

        [$team, $bot, $token] = self::slackParts();
        yield 'Slack' => ['https://hooks.' . 'slack.com/services/' . $team . '/' . $bot . '/' . $token, $auto, 'https://hooks.slack.com/services/' . self::M . '/' . self::M . '/' . self::M, [$team, $bot, $token]];

        yield 'Discord' => ['https://discord.com/api/webhooks/123456789/TEST-token_abc', $auto, 'https://discord.com/api/webhooks/' . self::M . '/' . self::M, ['123456789', 'TEST-token_abc']];
        yield 'healthchecks' => ['https://hc-ping.com/0a1b2c3d-0000-4000-8000-000000000000', $auto, 'https://hc-ping.com/' . self::M, ['0a1b2c3d-0000-4000-8000-000000000000']];
        yield 'kurz mit Ziffer' => ['https://a.example/a1b2', $auto, 'https://a.example/' . self::M, ['a1b2']];
        yield 'kurz groß' => ['https://a.example/Ab', $auto, 'https://a.example/' . self::M, ['Ab']];
        yield 'kurz Ziffer' => ['https://a.example/x9', $auto, 'https://a.example/' . self::M, ['x9']];
        yield 'Prozent' => ['https://a.example/%74%6F%6B%65%6E', $auto, 'https://a.example/' . self::M, ['%74%6F%6B%65%6E', 'token']];
        yield 'Matrix' => ['https://a.example/seg;jsessionid=abcdtest', $auto, 'https://a.example/' . self::M, ['jsessionid=abcdtest', 'abcdtest']];
        yield 'Doppelpunkt' => ['https://a.example/a:b', $auto, 'https://a.example/' . self::M, ['a:b']];
        yield 'Tilde' => ['https://a.example/~user', $auto, 'https://a.example/' . self::M, ['~user']];
        yield 'At' => ['https://a.example/x@secretname', $auto, 'https://a.example/' . self::M, ['x@secretname', 'secretname']];
        yield 'Gleich' => ['https://a.example/k=geheimwort', $auto, 'https://a.example/' . self::M, ['geheimwort']];
        yield 'Query' => ['https://a.example/cron.php?key=TEST-SECRET-do-not-use-123&mode=', $auto, 'https://a.example/cron.php?key=' . self::M . '&mode=' . self::M, ['TEST-SECRET-do-not-use-123']];
        yield 'Query ohne Wert' => ['https://a.example/?a&b=', $auto, 'https://a.example/?' . self::M . '&b=' . self::M, []];
        yield 'Query-Name wie Token' => ['https://a.example/?TEST1234=1', $auto, 'https://a.example/?' . self::M . '=' . self::M, ['TEST1234']];
        yield 'Query-Wert klein' => ['https://a.example/?user=geheimwortklein', $auto, 'https://a.example/?user=' . self::M, ['geheimwortklein']];
        yield 'Query-Name kodiert' => ['https://a.example/?%6Bey=geheim', $auto, 'https://a.example/?' . self::M . '=' . self::M, ['%6Bey', 'geheim']];
        yield 'Kleinbuchstaben-Token hidden' => ['https://a.example/hooks/abcdefghqrst', $hidden, 'https://a.example/' . self::M, ['abcdefghqrst', 'hooks']];
        yield 'Query hidden' => ['https://a.example/cron.php?key=TEST-SECRET-do-not-use-123&mode=x', $hidden, 'https://a.example/' . self::M . '?' . self::M, ['TEST-SECRET-do-not-use-123', 'cron.php', 'mode']];
        yield 'zu langes Wort' => ['https://a.example/abcdefghijklmnopq', $auto, 'https://a.example/' . self::M, ['abcdefghijklmnopq']];
        yield 'zu langes Segment' => ['https://a.example/abcdefgh-ijklmnop-qrstuvw', $auto, 'https://a.example/' . self::M, ['abcdefgh-ijklmnop-qrstuvw']];
        yield 'zu viele Wortteile' => ['https://a.example/a-b-c-d-e', $auto, 'https://a.example/' . self::M, ['a-b-c-d-e']];
        yield 'fremde Endung' => ['https://a.example/backup.sql', $auto, 'https://a.example/' . self::M, ['backup.sql']];
        yield 'Port und IPv6' => ['http://[fd00::1]:8080/api/x9z', $auto, 'http://[fd00::1]:8080/api/' . self::M, ['x9z']];
    }

    /**
     * @param list<string> $secrets
     */
    #[DataProvider('cases')]
    public function testDisplayIsExactAndLeaksNoSecretPart(string $url, DisplayPathMode $mode, string $expected, array $secrets): void
    {
        $display = UrlDisplay::fromParsed((new UrlPolicy())->parse($url), $mode);

        self::assertSame($expected, $display);
        foreach ($secrets as $secret) {
            self::assertNoLeak($display, $secret);
        }
    }

    public function testHarmlessPathWordsStayVisibleInAutoMode(): void
    {
        $policy = new UrlPolicy();

        self::assertSame(
            'https://img.example.org/tools/framework/api/cron.php?key=' . self::M,
            UrlDisplay::fromParsed($policy->parse('https://img.example.org/tools/framework/api/cron.php?key=TEST-SECRET-do-not-use-123'), DisplayPathMode::Auto),
        );
        // Dokumentiertes Restrisiko: reine Kleinbuchstaben bleiben im Modus auto sichtbar.
        self::assertSame('https://a.example/hooks/abcdefghqrst', UrlDisplay::fromParsed($policy->parse('https://a.example/hooks/abcdefghqrst'), DisplayPathMode::Auto));
        self::assertSame('https://a.example/a-b_c-d.json', UrlDisplay::fromParsed($policy->parse('https://a.example/a-b_c-d.json'), DisplayPathMode::Auto));
        self::assertSame('https://a.example/', UrlDisplay::fromParsed($policy->parse('https://a.example/'), DisplayPathMode::Auto));
        self::assertSame('https://a.example/api/', UrlDisplay::fromParsed($policy->parse('https://a.example/api/'), DisplayPathMode::Auto));
        self::assertSame('https://a.example', UrlDisplay::fromParsed($policy->parse('https://a.example'), DisplayPathMode::Hidden));
        self::assertSame('https://a.example:8443/' . self::M, UrlDisplay::fromParsed($policy->parse('https://a.example:8443/'), DisplayPathMode::Hidden));
        self::assertSame(2, UrlDisplay::VERSION);
        self::assertSame(DisplayPathMode::Hidden, DisplayPathMode::default(), 'Standard verbirgt den Pfad');
    }

    public function testLengthOfTokensAndValuesIsNotDerivable(): void
    {
        $policy = new UrlPolicy();
        foreach ([DisplayPathMode::Auto, DisplayPathMode::Hidden] as $mode) {
            $shown = static fn (string $url): string => UrlDisplay::fromParsed($policy->parse($url), $mode);

            self::assertSame($shown('https://a.example/hooks/A1'), $shown('https://a.example/hooks/A1' . str_repeat('b2C3', 20)));
            self::assertSame($shown('https://a.example/x?key=a'), $shown('https://a.example/x?key=' . str_repeat('a', 300)));
            self::assertSame($shown('https://a.example/x?key='), $shown('https://a.example/x?key=TEST-SECRET-do-not-use-123'));
            self::assertSame($shown('https://a.example/x?key'), $shown('https://a.example/x?TEST-SECRET-do-not-use-123'));
        }
        self::assertSame(
            UrlDisplay::fromParsed($policy->parse('https://a.example/one/two/three?a=1&b=2'), DisplayPathMode::Hidden),
            UrlDisplay::fromParsed($policy->parse('https://a.example/x?z=1'), DisplayPathMode::Hidden),
            'hidden verbirgt auch die Zahl der Segmente und Parameter',
        );
    }

    public function testRandomTokensAreNeverVisible(): void
    {
        $policy = new UrlPolicy();
        mt_srand(424242);
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
        for ($i = 0; $i < 1000; $i++) {
            $token = '';
            $length = mt_rand(8, 40);
            for ($c = 0; $c < $length; $c++) {
                $token .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            if (preg_match('/[0-9A-Z]/', $token) !== 1) {
                $token[mt_rand(0, $length - 1)] = (string) mt_rand(0, 9);
            }

            $display = UrlDisplay::fromParsed($policy->parse('https://a.example/api/' . $token . '?key=' . $token), DisplayPathMode::Auto);
            self::assertSame('https://a.example/api/' . self::M . '?key=' . self::M, $display, 'Token Nr. ' . $i);
            self::assertNoLeak($display, $token);
        }
    }

    /**
     * Weder das Geheimnis noch eine Kodierung davon noch ein Teilstring ab vier Zeichen, der nicht zugleich in
     * einem erlaubten sichtbaren Teil (Origin, sichtbares Segment, sichtbarer Query-Name) steht.
     */
    private static function assertNoLeak(string $display, string $secret): void
    {
        foreach ([$secret, rawurlencode($secret), urlencode($secret), base64_encode($secret)] as $form) {
            self::assertStringNotContainsString($form, $display);
        }

        $visible = [];
        if (preg_match('~^([a-z]+://[^/?]+)(.*)$~', $display, $m) === 1) {
            $visible[] = $m[1];
            $parts = preg_split('~[/?&=]~', $m[2]);
            foreach ($parts === false ? [] : $parts as $part) {
                if ($part !== '' && $part !== self::M && (UrlDisplay::isVisibleSegment($part) || UrlDisplay::isVisibleQueryName($part))) {
                    $visible[] = $part;
                }
            }
        }
        $length = strlen($secret);
        for ($size = 4; $size <= $length; $size++) {
            for ($start = 0; $start + $size <= $length; $start++) {
                $piece = substr($secret, $start, $size);
                if (!str_contains($display, $piece)) {
                    continue;
                }
                $allowed = false;
                foreach ($visible as $part) {
                    $allowed = $allowed || str_contains($part, $piece);
                }
                self::assertTrue($allowed, 'Teil des Geheimnisses sichtbar (Länge ' . $size . ')');
            }
        }
    }

    /**
     * Slack-Format zur Laufzeit zusammengesetzt, offensichtlich unecht.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function slackParts(): array
    {
        return ['T' . 'TESTTEAM0', 'B' . 'TESTBOT00', 'TEST' . str_repeat('x', 16) . 'FAKE'];
    }
}
