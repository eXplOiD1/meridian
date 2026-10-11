<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Http;

use Meridian\Auth\CsrfGuard;
use Meridian\Config;
use Meridian\Http\JsonBody;
use Meridian\Http\JsonReply;
use Meridian\Http\Kernel;
use Meridian\Http\ValidationFailed;
use Meridian\Security\SecretMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class KernelTest extends TestCase
{
    private const SECRET = 'TEST-SECRET-do-not-use-123';

    private function kernel(): Kernel
    {
        $kernel = new Kernel(new Config('/nonexistent', 'prod', 'Europe/Berlin', 'test'), new SecretMasker());
        $ok = static fn (Request $r): Response => JsonReply::json(['id' => $r->attributes->get('id'), 'm' => $r->getMethod()]);
        $kernel->get('t.get', '/api/t/{id}', $ok, ['id' => '[1-9][0-9]{0,17}']);
        $kernel->post('t.post', '/api/t/{id}', $ok, ['id' => '[1-9][0-9]{0,17}']);
        $kernel->put('t.put', '/api/t/{id}', $ok, ['id' => '[1-9][0-9]{0,17}']);
        $kernel->delete('t.del', '/api/t/{id}', $ok, ['id' => '[1-9][0-9]{0,17}']);
        $kernel->post('t.invalid', '/api/invalid', static function (Request $r): Response {
            // Der Wert aus der Anfrage darf nie in der Meldung landen.
            throw new ValidationFailed(['title' => 'Der Titel ist zu lang. Höchstens 200 Zeichen.', 'url' => 'Die Adresse ist ungültig.']);
        });
        $kernel->put('t.csrf', '/api/csrf', static function (Request $r): Response {
            return (new CsrfGuard())->originMatches($r) ? JsonReply::json(['ok' => true]) : JsonReply::error(403, 'Herkunft nicht erlaubt.');
        });
        $kernel->delete('t.csrfd', '/api/csrf', static function (Request $r): Response {
            return (new CsrfGuard())->check($r, 'TEST-SESSION-do-not-use') ? JsonReply::json(['ok' => true]) : JsonReply::error(403, 'Herkunft nicht erlaubt.');
        });
        $kernel->post('t.utf8', '/api/utf8', static fn (Request $r): Response => JsonReply::json(['text' => "ab\xB1\xFFcd"]));

        return $kernel;
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(string $method, string $path, string $body = '', array $headers = []): Response
    {
        $server = ['HTTP_HOST' => 'meridian.test', 'REMOTE_ADDR' => '10.0.0.5'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->kernel()->handle(Request::create('https://meridian.test' . $path, $method, [], [], [], $server, $body));
    }

    /**
     * @return array<mixed>
     */
    private static function decode(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function methods(): array
    {
        return ['GET' => ['GET'], 'POST' => ['POST'], 'PUT' => ['PUT'], 'DELETE' => ['DELETE']];
    }

    #[DataProvider('methods')]
    public function testPathParameterReachesHandlerForEveryMethod(string $method): void
    {
        $response = $this->call($method, '/api/t/42');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"id":"42","m":"' . $method . '"}', $response->getContent());
    }

    #[DataProvider('methods')]
    public function testInvalidIdIsNotFound(string $method): void
    {
        foreach (['/api/t/0', '/api/t/abc', '/api/t/1234567890123456789', '/api/t/01'] as $path) {
            self::assertSame(404, $this->call($method, $path)->getStatusCode(), $path);
        }
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        $response = $this->call('PATCH', '/api/t/42');
        self::assertSame(405, $response->getStatusCode());
        $allow = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
        sort($allow);
        self::assertSame(['DELETE', 'GET', 'POST', 'PUT'], $allow);
        self::assertSame('Methode nicht erlaubt.', self::decode($response)['error'] ?? null);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testUnknownPathIs404(): void
    {
        self::assertSame(404, $this->call('PUT', '/api/gibt-es-nicht')->getStatusCode());
    }

    public function testValidationFailedBecomes422WithFieldsAndNoInputValue(): void
    {
        $response = $this->call('POST', '/api/invalid', (string) json_encode(['title' => self::SECRET, 'url' => 'https://u:' . self::SECRET . '@h.test/']));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString(self::SECRET, (string) $response->getContent());

        $data = self::decode($response);
        self::assertIsString($data['error']);
        self::assertSame(['title' => 'Der Titel ist zu lang. Höchstens 200 Zeichen.', 'url' => 'Die Adresse ist ungültig.'], $data['fields']);
    }

    public function testValidationFailedFieldHelper(): void
    {
        self::assertSame(['a.b' => 'Fehler.'], ValidationFailed::field('a.b', 'Fehler.')->fields());
    }

    public function testInvalidUtf8IsSubstitutedNotFailing(): void
    {
        $response = $this->call('POST', '/api/utf8');
        self::assertSame(200, $response->getStatusCode());
        $data = self::decode($response);
        self::assertSame("ab\u{FFFD}\u{FFFD}cd", $data['text'] ?? null);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testJsonBodyDepthIsLimited(): void
    {
        $deep = '{"a":{"b":{"c":{"d":1}}}}'; // Tiefe 5
        self::assertNull(JsonBody::object($deep, 1000));
        self::assertNull(JsonBody::object($deep, 1000, 4));
        self::assertNotNull(JsonBody::object($deep, 1000, 5));
        // Obergrenze: auch ein großer Wunsch bleibt bei 8.
        self::assertNull(JsonBody::object(str_repeat('{"a":', 9) . '1' . str_repeat('}', 9), 1000, 1000));
        self::assertNotNull(JsonBody::object(str_repeat('{"a":', 7) . '1' . str_repeat('}', 7), 1000, 1000));
        self::assertNull(JsonBody::object('[1', 1000));
        self::assertNull(JsonBody::object('{"a":1}', 3));
    }

    // --- CSRF/Origin gilt für PUT und DELETE wie für POST ----------------------------------------------

    public function testOriginCheckAppliesToPutAndDelete(): void
    {
        self::assertSame(403, $this->call('PUT', '/api/csrf')->getStatusCode(), 'ohne Origin');
        self::assertSame(403, $this->call('PUT', '/api/csrf', '', ['Origin' => 'https://evil.test'])->getStatusCode());
        self::assertSame(200, $this->call('PUT', '/api/csrf', '', ['Origin' => 'https://meridian.test'])->getStatusCode());

        $token = (new CsrfGuard())->tokenFor('TEST-SESSION-do-not-use');
        self::assertSame(403, $this->call('DELETE', '/api/csrf', '', ['Origin' => 'https://meridian.test'])->getStatusCode(), 'ohne Token');
        self::assertSame(403, $this->call('DELETE', '/api/csrf', '', ['Origin' => 'https://meridian.test', 'X-CSRF-Token' => 'falsch'])->getStatusCode());
        self::assertSame(403, $this->call('DELETE', '/api/csrf', '', ['Origin' => 'https://evil.test', 'X-CSRF-Token' => $token])->getStatusCode());
        self::assertSame(200, $this->call('DELETE', '/api/csrf', '', ['Origin' => 'https://meridian.test', 'X-CSRF-Token' => $token])->getStatusCode());
    }
}
