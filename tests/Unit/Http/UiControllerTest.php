<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Http;

use Meridian\Config;
use Meridian\Database\Connection;
use Meridian\Http\AppFactory;
use Meridian\Http\Kernel;
use Meridian\Security\SecretBox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class UiControllerTest extends TestCase
{
    private function kernel(string $environment = 'prod', ?string $uiDir = null): Kernel
    {
        $config = new Config('/nonexistent', $environment, 'Europe/Berlin', 'test', [], $uiDir ?? dirname(__DIR__, 2) . '/Fixtures/ui');

        return AppFactory::create($config, Connection::inMemory(), new SecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    private function get(Kernel $kernel, string $path, string $scheme = 'https', ?string $etag = null): Response
    {
        $server = ['HTTP_HOST' => 'meridian.test'];
        if ($etag !== null) {
            $server['HTTP_IF_NONE_MATCH'] = $etag;
        }

        return $kernel->handle(Request::create($scheme . '://meridian.test' . $path, 'GET', [], [], [], $server));
    }

    public function testRootAndAppRedirectToTheApp(): void
    {
        foreach (['/', '/app'] as $path) {
            $response = $this->get($this->kernel(), $path);
            self::assertSame(302, $response->getStatusCode(), $path);
            self::assertSame('/app/', $response->headers->get('Location'));
        }
    }

    public function testServesTheStartPageWithAllSecurityHeaders(): void
    {
        $response = $this->get($this->kernel(), '/app/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringNotContainsString('unsafe', $csp);
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    /**
     * @return array<string, array{string, non-empty-string}>
     */
    public static function assets(): array
    {
        return [
            'Skript' => ['/app/assets/app.js', 'text/javascript'],
            'Stil' => ['/app/assets/app.css', 'text/css'],
            'Schrift' => ['/app/fonts/f.woff2', 'font/woff2'],
        ];
    }

    /**
     * @param non-empty-string $type
     */
    #[DataProvider('assets')]
    public function testAssetsGetTheRightTypeLongCachingAndTheSecurityHeaders(string $path, string $type): void
    {
        $response = $this->get($this->kernel(), $path);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith($type, (string) $response->headers->get('Content-Type'));
        foreach (['public', 'max-age=31536000', 'immutable'] as $directive) {
            self::assertStringContainsString($directive, (string) $response->headers->get('Cache-Control'));
        }
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function testConditionalRequestsGet304(): void
    {
        $first = $this->get($this->kernel(), '/app/assets/app.js');
        $etag = $first->headers->get('ETag');
        self::assertNotNull($etag);

        $second = $this->get($this->kernel(), '/app/assets/app.js', 'https', $etag);

        self::assertSame(304, $second->getStatusCode());
        self::assertSame('', (string) $second->getContent());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenPaths(): array
    {
        return [
            'Verzeichniswechsel' => ['/app/../secret.txt'],
            'Verzeichniswechsel kodiert' => ['/app/%2e%2e/secret.txt'],
            'verschachtelt' => ['/app/assets/../../secret.txt'],
            'Backslash' => ['/app/assets%5C..%5C..%5Csecret.txt'],
            'Nullbyte' => ['/app/index.html%00.js'],
            'PHP-Datei' => ['/app/assets/evil.php'],
            'ohne Endung' => ['/app/assets/noext'],
            'Quelltext der Anwendung' => ['/app/../src/Config.php'],
            'versteckte Datei' => ['/app/.env'],
            'Verzeichnis' => ['/app/assets'],
            'nicht vorhanden' => ['/app/assets/nichts.js'],
            'Sonderzeichen' => ['/app/assets/a b.js'],
        ];
    }

    #[DataProvider('forbiddenPaths')]
    public function testNothingOutsideTheUiFolderOrWithAForbiddenTypeIsEverServed(string $path): void
    {
        $response = $this->get($this->kernel(), $path);

        self::assertContains($response->getStatusCode(), [302, 400, 404], $path);
        self::assertStringNotContainsString('GEHEIM-NICHT-AUSLIEFERN', (string) $response->getContent());
        self::assertStringNotContainsString('<?php', (string) $response->getContent());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testMissingBuildGivesAClearMessageNotAStackTrace(): void
    {
        $response = $this->get($this->kernel('prod', '/gibt/es/nicht'), '/app/');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('npm', (string) $response->getContent());
        self::assertStringNotContainsString('/gibt/es/nicht', (string) $response->getContent());
        self::assertSame(404, $this->get($this->kernel('prod', ''), '/app/assets/app.js')->getStatusCode());
    }

    public function testNoHstsInDevOrOverPlainHttp(): void
    {
        self::assertNull($this->get($this->kernel('dev'), '/app/')->headers->get('Strict-Transport-Security'));
        self::assertNull($this->get($this->kernel(), '/app/', 'http')->headers->get('Strict-Transport-Security'));
    }

    public function testApiRoutesAreNotShadowedByTheUiRoutes(): void
    {
        $response = $this->get($this->kernel(), '/api/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"status":"ok"', (string) $response->getContent());
        self::assertSame(401, $this->get($this->kernel(), '/api/auth/me')->getStatusCode());
    }
}
