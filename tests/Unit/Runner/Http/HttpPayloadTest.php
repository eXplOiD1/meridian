<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Runner\Http;

use Meridian\Runner\Http\HttpPayload;
use Meridian\Runner\Http\InvalidPayload;
use Meridian\Runner\Http\InvalidUrl;
use Meridian\Security\SecretBox;
use Meridian\Security\SecretMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HttpPayload Format v1: Rundreise, nur bekannte Version, keine Klartexte in Debug-Ausgaben und Stacktraces,
 * nicht serialisierbar, Header-/Body-Regeln (§3.2), Registrierung aller geheimen Teile im Masker (§5.3).
 */
final class HttpPayloadTest extends TestCase
{
    private const URL = 'https://api.example.org/bot123456:TEST-ABCdef_ghi/send?key=TEST-SECRET-do-not-use-123&mode=a%2Fb';
    private const BEARER = 'TEST-BEARER-do-not-use-456';
    private const BASIC_USER = 'testuser-fake';
    private const BASIC_PASS = 'TEST-PASS-do-not-use-789';
    private const JSON_LEAF = 'TEST-JSONLEAF-do-not-use';

    public function testRoundTripInFormatV1(): void
    {
        $payload = $this->payload();
        $json = $payload->toJson();

        self::assertSame(
            ['v' => 1, 'url' => self::URL, 'headers' => [['Authorization', 'Bearer ' . self::BEARER], ['X-Basic', 'Basic ' . base64_encode(self::BASIC_USER . ':' . self::BASIC_PASS)], ['Content-Type', 'application/json']], 'body' => '{"a":{"b":["' . self::JSON_LEAF . '"]},"short":"abc"}'],
            json_decode($json, true, 8, JSON_THROW_ON_ERROR),
        );
        $back = HttpPayload::fromJson($json);
        self::assertSame($json, $back->toJson());
        self::assertSame(self::URL, $back->url()->toUrl());
        self::assertSame(3, $back->headerCount());
        self::assertTrue($back->hasBody());

        $none = new HttpPayload('http://a.example');
        self::assertSame('{"v":1,"url":"http://a.example","headers":[],"body":null}', $none->toJson());
        self::assertFalse(HttpPayload::fromJson($none->toJson())->hasBody());
    }

    public function testRoundTripThroughSecretBoxKeepsPlaintextOutOfTheCiphertext(): void
    {
        $box = new SecretBox(sodium_crypto_secretbox_keygen());
        $stored = $box->encrypt($this->payload()->toJson());

        self::assertStringStartsWith('v1:', $stored);
        foreach (['TEST-SECRET', 'TEST-BEARER', 'TEST-ABCdef', self::JSON_LEAF] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }
        self::assertSame($this->payload()->toJson(), HttpPayload::fromJson($box->decrypt($stored))->toJson());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function badStored(): iterable
    {
        $unreadable = 'Gespeicherte Anfrage nicht lesbar (Schlüssel oder Format). Anfrage im Job neu eingeben.';
        $unknown = 'Gespeicherte Anfrage hat ein unbekanntes Format. Anfrage im Job neu eingeben.';
        yield 'kein JSON' => ['{"v":1,"url":"https://a.example/?key=TEST-SECRET-do-not-use-123"', $unreadable];
        yield 'Version 2' => ['{"v":2,"url":"https://a.example/?key=TEST-SECRET-do-not-use-123","headers":[],"body":null}', $unknown];
        yield 'Version als Text' => ['{"v":"1","url":"https://a.example/?key=TEST-SECRET-do-not-use-123","headers":[],"body":null}', $unknown];
        yield 'ohne Version' => ['{"url":"https://a.example/?key=TEST-SECRET-do-not-use-123","headers":[],"body":null}', $unreadable];
        yield 'Liste' => ['["https://a.example/?key=TEST-SECRET-do-not-use-123"]', $unreadable];
        yield 'fehlender Schlüssel' => ['{"v":1,"url":"https://a.example/?key=TEST-SECRET-do-not-use-123","headers":[]}', $unreadable];
        yield 'zusätzlicher Schlüssel' => ['{"v":1,"url":"https://a.example/?key=TEST-SECRET-do-not-use-123","headers":[],"body":null,"x":1}', $unreadable];
        yield 'Header als Objekt' => ['{"v":1,"url":"https://a.example/","headers":{"Authorization":"TEST-SECRET-do-not-use-123"},"body":null}', $unreadable];
        yield 'Header-Paar zu lang' => ['{"v":1,"url":"https://a.example/","headers":[["A","TEST-SECRET-do-not-use-123","x"]],"body":null}', $unreadable];
        yield 'Header-Wert Zahl' => ['{"v":1,"url":"https://a.example/","headers":[["A",123456]],"body":null}', $unreadable];
        yield 'Body Zahl' => ['{"v":1,"url":"https://a.example/","headers":[],"body":123456}', $unreadable];
        yield 'URL ungültig' => ['{"v":1,"url":"ftp://a.example/?key=TEST-SECRET-do-not-use-123","headers":[],"body":null}', $unreadable];
        yield 'Header verboten' => ['{"v":1,"url":"https://a.example/","headers":[["Host","TEST-SECRET-do-not-use-123"]],"body":null}', $unreadable];
        yield 'zu tief' => ['{"v":1,"url":"https://a.example/","headers":[[[[[[[["TEST-SECRET-do-not-use-123"]]]]]]]],"body":null}', $unreadable];
    }

    #[DataProvider('badStored')]
    public function testStoredGarbageIsRejectedWithAFixedMessage(string $json, string $message): void
    {
        try {
            HttpPayload::fromJson($json);
            self::fail('angenommen');
        } catch (InvalidPayload $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame('payload', $e->field);
            self::assertNull($e->getPrevious(), 'keine innere Ausnahme mit Details');
            self::assertStringNotContainsString('TEST-SECRET', $e->getTraceAsString());
        }
    }

    public function testDebugOutputsNeverShowTheContent(): void
    {
        $payload = $this->payload();
        $outputs = [print_r($payload, true), json_encode($payload, JSON_THROW_ON_ERROR), self::dump($payload), print_r([$payload], true)];
        foreach ($outputs as $text) {
            foreach (['TEST-SECRET', 'TEST-BEARER', 'TEST-ABCdef', self::JSON_LEAF, 'api.example.org', 'Authorization'] as $secret) {
                self::assertStringNotContainsString($secret, $text);
            }
        }
        self::assertSame('{"v":1,"header_count":3,"has_body":true}', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testSerializeThrowsAndForgedSerializedDataIsRejected(): void
    {
        try {
            serialize($this->payload());
            self::fail('serialisiert');
        } catch (\LogicException $e) {
            self::assertStringNotContainsString('TEST-SECRET', $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        unserialize('O:' . strlen(HttpPayload::class) . ':"' . HttpPayload::class . '":0:{}', ['allowed_classes' => [HttpPayload::class]]);
    }

    public function testStackTracesOfRejectedPayloadsContainNoPlaintext(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            foreach ([
                static fn (): HttpPayload => new HttpPayload('https://a.example/?key=TEST-SECRET-do-not-use-123', [['Host', 'TEST-SECRET-do-not-use-123']]),
                static fn (): HttpPayload => new HttpPayload('ftp://a.example/?key=TEST-SECRET-do-not-use-123'),
                static fn (): HttpPayload => new HttpPayload('https://a.example/', [], "TEST-SECRET-do-not-use-123\xFF"),
                static fn (): HttpPayload => HttpPayload::fromJson('{"v":1,"url":"https://a.example/","headers":[["X","TEST-SECRET-do-not-use-123\r\n"]],"body":null}'),
            ] as $i => $attempt) {
                try {
                    $attempt();
                    self::fail('angenommen: Nr. ' . $i);
                } catch (InvalidPayload | InvalidUrl $e) {
                    self::assertStringNotContainsString('TEST-SECRET', $e->getMessage(), 'Nr. ' . $i);
                    self::assertStringNotContainsString('TEST-SECRET', $e->getTraceAsString(), 'Nr. ' . $i);
                    self::assertStringNotContainsString('TEST-SECRET', print_r($e->getTrace(), true), 'Nr. ' . $i);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /**
     * @return iterable<string, array{0: list<array{0: string, 1: string}>, 1: ?string, 2: string}>
     */
    public static function badRequests(): iterable
    {
        yield 'Host' => [[['host', 'evil.example']], null, 'request.headers[0].name'];
        yield 'Content-Length' => [[['Content-Length', '1']], null, 'request.headers[0].name'];
        yield 'Transfer-Encoding' => [[['Transfer-Encoding', 'chunked']], null, 'request.headers[0].name'];
        yield 'Proxy-Authorization' => [[['X-A', 'b'], ['Proxy-Authorization', 'Basic TEST-SECRET-do-not-use-123']], null, 'request.headers[1].name'];
        yield 'Name mit Leerzeichen' => [[['X A', 'b']], null, 'request.headers[0].name'];
        yield 'Name mit Doppelpunkt' => [[['X-A:', 'b']], null, 'request.headers[0].name'];
        yield 'Name leer' => [[['', 'b']], null, 'request.headers[0].name'];
        yield 'Name zu lang' => [[[str_repeat('a', 65), 'b']], null, 'request.headers[0].name'];
        yield 'CRLF im Wert' => [[['X-A', "TEST-SECRET-do-not-use-123\r\nHost: evil.example"]], null, 'request.headers[0].value'];
        yield 'NUL im Wert' => [[['X-A', "a\0b"]], null, 'request.headers[0].value'];
        yield 'Leerraum am Rand' => [[['X-A', ' TEST-SECRET-do-not-use-123']], null, 'request.headers[0].value'];
        yield 'Wert zu lang' => [[['X-A', str_repeat('a', 4097)]], null, 'request.headers[0].value'];
        yield 'kein UTF-8' => [[['X-A', "\xC3\x28"]], null, 'request.headers[0].value'];
        yield 'zu viele' => [array_fill(0, 31, ['X-A', 'b']), null, 'request.headers'];
        yield 'zusammen zu groß' => [array_fill(0, 5, ['X-A', str_repeat('a', 4000)]), null, 'request.headers'];
        yield 'Body zu groß' => [[], str_repeat('a', 65537), 'request.body'];
        yield 'Body kein UTF-8' => [[], "TEST-SECRET-do-not-use-123\xFF", 'request.body'];
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     */
    #[DataProvider('badRequests')]
    public function testRequestRulesRejectWithFieldAndWithoutValue(array $headers, ?string $body, string $field): void
    {
        try {
            new HttpPayload('https://a.example/', $headers, $body);
            self::fail('angenommen');
        } catch (InvalidPayload $e) {
            self::assertSame($field, $e->field);
            self::assertStringNotContainsString('TEST-SECRET', $e->getMessage());
            self::assertStringNotContainsString('evil', $e->getMessage());
        }
    }

    public function testLimitsAreInclusive(): void
    {
        $payload = new HttpPayload('https://a.example/', array_fill(0, 30, ['X-A', 'b']), str_repeat('ä', 32768));
        self::assertSame(30, $payload->headerCount());
        self::assertSame(1, (new HttpPayload('https://a.example/', [['X-A', str_repeat('a', 4096)]]))->headerCount());
        self::assertSame(1, (new HttpPayload('https://a.example/', [['X-A', '']]))->headerCount(), 'leerer Wert ist erlaubt');
    }

    public function testRegisterInMasksEveryPartOfTheRequest(): void
    {
        $masker = new SecretMasker();
        $this->payload()->registerIn($masker);
        $form = new HttpPayload('https://a.example/', [['content-type', 'application/x-www-form-urlencoded; charset=utf-8']], 'user=testuser&pin=TEST-FORMPIN-fake%2B1');
        $form->registerIn($masker);

        $text = implode("\n", [
            'GET ' . self::URL,
            'https://api.example.org/bot123456:TEST-ABCdef_ghi/send',
            'segment TEST-ABCdef_ghi oder ' . rawurlencode('bot123456:TEST-ABCdef_ghi'),
            'value TEST-SECRET-do-not-use-123 und a/b roh a%2Fb',
            'Authorization: Bearer ' . self::BEARER,
            'token alone ' . self::BEARER,
            'basic ' . base64_encode(self::BASIC_USER . ':' . self::BASIC_PASS) . ' user ' . self::BASIC_USER . ' pass ' . self::BASIC_PASS,
            'leaf ' . self::JSON_LEAF . ' base64 ' . base64_encode(self::JSON_LEAF),
            'form TEST-FORMPIN-fake+1 und TEST-FORMPIN-fake%2B1',
            'harmlos: send ok mode abc',
        ]);
        $masked = $masker->mask($text);

        foreach (['TEST-SECRET', 'TEST-BEARER', 'TEST-ABCdef', 'bot123456', self::BASIC_USER, 'TEST-PASS', self::JSON_LEAF, base64_encode(self::JSON_LEAF), 'TEST-FORMPIN', 'api.example.org/bot'] as $secret) {
            self::assertStringNotContainsString($secret, $masked);
        }
        self::assertStringContainsString('harmlos: send ok mode abc', $masked, 'harmlose und kurze Teile bleiben lesbar');
    }

    public function testRegisterInDoesNotNeedAnyQuery(): void
    {
        $masker = new SecretMasker();
        (new HttpPayload('https://hc-ping.example/0a1b2c3d-0000-4000-8000-000000000000'))->registerIn($masker);

        self::assertSame('ping ' . SecretMasker::MASK . ' ok', $masker->mask('ping 0a1b2c3d-0000-4000-8000-000000000000 ok'));
    }

    private function payload(): HttpPayload
    {
        return new HttpPayload(
            self::URL,
            [
                ['Authorization', 'Bearer ' . self::BEARER],
                ['X-Basic', 'Basic ' . base64_encode(self::BASIC_USER . ':' . self::BASIC_PASS)],
                ['Content-Type', 'application/json'],
            ],
            '{"a":{"b":["' . self::JSON_LEAF . '"]},"short":"abc"}',
        );
    }

    private static function dump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }
}
