<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Runner\Http;

use Meridian\Runner\Http\InvalidUrl;
use Meridian\Runner\Http\UrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * URL-Syntax §3.3 und die Syntax-Fälle aus §7.1. Meldungen enthalten nie die URL.
 */
final class UrlPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rejected(): iterable
    {
        yield 'ftp' => ['ftp://files.example.org/x'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://evil.example:70/_x'];
        yield 'javascript' => ['javascript://alert.example/x'];
        yield 'ohne Schema' => ['//evil.example/x'];
        yield 'Schema ohne //' => ['http:evil.example/x'];
        yield 'Zugangsdaten' => ['http://user:pw@evil.example/'];
        yield 'doppeltes @' => ['http://a@b@evil.example/'];
        yield 'Backslash' => ['http://host\@evil.example/'];
        yield 'Dezimal-IP' => ['http://2130706433/'];
        yield 'Oktal-IP' => ['http://017700000001/'];
        yield 'Hex-IP' => ['http://0x7f.0.0.1/'];
        yield 'Kurz-IP' => ['http://127.1/'];
        yield 'Null' => ['http://0/'];
        yield 'führende Null' => ['http://127.0.0.01/'];
        yield 'Zonen-ID' => ['http://[fe80::1%25eth0]/'];
        yield 'IPv4 in Klammern' => ['http://[127.0.0.1]/'];
        yield 'IPv6 ohne Klammern' => ['http://::1/'];
        yield 'Klammer offen' => ['http://[::1/'];
        yield 'Leerzeichen' => ['http://evil.example/a b'];
        yield 'CR LF' => ["http://evil.example/a\r\nX-Injected: 1"];
        yield 'Tab' => ["http://evil.example/\tx"];
        yield 'NUL' => ["http://evil.example/\0x"];
        yield 'Fragment' => ['http://evil.example/#frag'];
        yield 'zu lang' => ['http://evil.example/' . str_repeat('a', 2030)];
        yield 'Umlaut-Host' => ['http://bücher.example/'];
        yield 'Unicode im Pfad' => ['http://evil.example/ä'];
        yield 'localhost' => ['http://localhost/'];
        yield 'LOCALHOST' => ['http://LOCALHOST:8080/'];
        yield 'sub.localhost' => ['http://foo.localhost/'];
        yield 'metadata.google.internal' => ['http://metadata.google.internal/computeMetadata/v1/'];
        yield 'metadata' => ['http://metadata/'];
        yield 'leerer Host' => ['http:///x'];
        yield 'leerer Port' => ['http://evil.example:/'];
        yield 'Port 0' => ['http://evil.example:0/'];
        yield 'Port zu groß' => ['http://evil.example:65536/'];
        yield 'Port mit Null' => ['http://evil.example:080/'];
        yield 'Port Buchstaben' => ['http://evil.example:8o/'];
        yield 'zwei Doppelpunkte' => ['http://evil.example:80:81/'];
        yield 'Punkt am Ende' => ['http://evil.example./'];
        yield 'leeres Label' => ['http://evil..example/'];
        yield 'Bindestrich am Rand' => ['http://-evil.example/'];
        yield 'Unterstrich im Host' => ['http://evil_host.example/'];
        yield 'Label zu lang' => ['http://' . str_repeat('a', 64) . '.example/'];
        yield 'numerisches letztes Label' => ['http://evil.123/'];
        yield 'kaputte Prozentkodierung' => ['http://evil.example/%zz'];
        yield 'halbe Prozentkodierung' => ['http://evil.example/%4'];
        yield 'spitze Klammer' => ['http://evil.example/<script>'];
        yield 'Anführungszeichen' => ['http://evil.example/"x"'];
        yield 'geschweifte Klammer' => ['http://evil.example/?a={x}'];
        yield 'Pipe' => ['http://evil.example/?a=b|c'];
        yield 'leer' => [''];
    }

    #[DataProvider('rejected')]
    public function testRejectsWithoutEchoingTheUrl(string $url): void
    {
        try {
            (new UrlPolicy())->parse($url);
            self::fail('angenommen');
        } catch (InvalidUrl $e) {
            $message = $e->getMessage();
            self::assertNotSame('', $message);
            if ($url !== '') {
                self::assertStringNotContainsString($url, $message);
            }
            foreach (['evil', 'passwd', 'user:pw', 'eth0', 'script', 'bücher', 'files.example', 'computeMetadata'] as $part) {
                self::assertStringNotContainsString($part, $message);
            }
        }
    }

    public function testMessagesNameTheFix(): void
    {
        $message = static function (string $url): string {
            try {
                (new UrlPolicy())->parse($url);
            } catch (InvalidUrl $e) {
                return $e->getMessage();
            }

            return '';
        };

        self::assertStringContainsString('Authorization', $message('https://user:TEST-SECRET-do-not-use-123@a.example/'));
        self::assertStringContainsString('Authorization', $message('https://a@b.example/'));
        self::assertStringContainsString('#', $message('https://a.example/#x'));
        self::assertStringContainsString('gesperrt', $message('https://localhost/'));
        self::assertStringContainsString('Zonen-ID', $message('https://[fe80::1%25eth0]/'));
        self::assertStringContainsString('http://', $message('ftp://a.example/'));
    }

    public function testNormalisesSchemeHostAndDefaultPort(): void
    {
        $url = (new UrlPolicy())->parse('HTTPS://Img.Example.ORG:443/Tools/cron.php?key=TEST-SECRET-do-not-use-123&x=%2F');

        self::assertSame('https', $url->scheme);
        self::assertSame('img.example.org', $url->host);
        self::assertSame(443, $url->port);
        self::assertFalse($url->isIpLiteral);
        self::assertSame('/Tools/cron.php', $url->path());
        self::assertSame('key=TEST-SECRET-do-not-use-123&x=%2F', $url->query());
        self::assertSame('https://img.example.org', $url->origin());
        self::assertSame('https://img.example.org/Tools/cron.php?key=TEST-SECRET-do-not-use-123&x=%2F', $url->toUrl());
    }

    public function testIpLiteralsAndPorts(): void
    {
        $policy = new UrlPolicy();

        $v6 = $policy->parse('http://[2001:DB8:0:0::1]:8080/a');
        self::assertSame('2001:db8::1', $v6->host);
        self::assertTrue($v6->isIpLiteral);
        self::assertTrue($v6->isIpv6());
        self::assertSame('http://[2001:db8::1]:8080', $v6->origin());

        $mapped = $policy->parse('http://[::ffff:127.0.0.1]/');
        self::assertSame('::ffff:127.0.0.1', $mapped->host);

        $v4 = $policy->parse('http://192.168.1.5:8099');
        self::assertSame('192.168.1.5', $v4->host);
        self::assertTrue($v4->isIpLiteral);
        self::assertSame(8099, $v4->port);
        self::assertSame('', $v4->path());
        self::assertNull($v4->query());
        self::assertSame('http://192.168.1.5:8099', $v4->toUrl());

        self::assertSame(80, $policy->parse('http://0.0.0.0/')->port, '0.0.0.0 ist Syntax, gesperrt wird es über die Adresse');
        self::assertSame('', $policy->parse('http://a.example/?')->query(), 'leere Query bleibt erhalten');
        self::assertSame('/a/@b:c;d=e', $policy->parse('http://a.example/a/@b:c;d=e')->path(), '@ nach der Authority ist erlaubt');
        self::assertSame('xn--bcher-kva.example', $policy->parse('https://xn--bcher-kva.example/')->host);
        self::assertSame('nextcloud', $policy->parse('http://nextcloud/')->host, 'einteilige Namen (Docker-Dienste)');
        self::assertSame(65535, $policy->parse('http://a.example:65535/')->port);
        self::assertSame(2048, strlen('http://a.example/' . str_repeat('a', 2048 - 17)));
        self::assertSame('a.example', $policy->parse('http://a.example/' . str_repeat('a', 2048 - 17))->host, 'genau 2048 Byte');
    }

    public function testParsedUrlHidesPathAndQueryInDebugOutput(): void
    {
        $url = (new UrlPolicy())->parse('https://a.example/bot123456:TEST-ABCdef_ghi/x?key=TEST-SECRET-do-not-use-123');

        foreach ([print_r($url, true), json_encode($url, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), self::dump($url)] as $text) {
            self::assertStringNotContainsString('TEST-SECRET', $text);
            self::assertStringNotContainsString('TEST-ABCdef', $text);
            self::assertStringContainsString('https://a.example', $text);
        }
        $this->expectException(\LogicException::class);
        serialize($url);
    }

    private static function dump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }
}
