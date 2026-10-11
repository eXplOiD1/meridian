<?php

declare(strict_types=1);

namespace Meridian\Tests\Support;

use Meridian\Config;
use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Http\AppFactory;
use Meridian\Http\Kernel;
use Meridian\Security\PasswordHasher;
use Meridian\Security\SecretBox;
use Meridian\User\UserRepository;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gemeinsame Hilfen für Tests, die die ganze HTTP-Schicht mit In-Memory-Datenbank durchlaufen.
 */
trait ApiHarness
{
    public const PASSWORD = 'TEST-PASSWORD-do-not-use-1';
    public const COOKIE = '__Host-meridian_session';

    private ?Connection $dbInstance = null;
    private ?MutableClock $clockInstance = null;
    private ?UserRepository $usersInstance = null;
    private ?Kernel $kernelInstance = null;
    private ?SecretBox $boxInstance = null;

    private function db(): Connection
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = Connection::inMemory();
            (new Migrator($this->dbInstance, dirname(__DIR__, 2) . '/migrations'))->migrate();
        }

        return $this->dbInstance;
    }

    private function clock(): MutableClock
    {
        return $this->clockInstance ??= new MutableClock();
    }

    private function box(): SecretBox
    {
        // Zufälliger Schlüssel je Test, nie ein Literal im Quelltext.
        return $this->boxInstance ??= new SecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    private function users(): UserRepository
    {
        return $this->usersInstance ??= new UserRepository($this->db(), new PasswordHasher());
    }

    private function kernel(): Kernel
    {
        return $this->kernelInstance ??= $this->buildKernel('prod');
    }

    private function buildKernel(string $environment): Kernel
    {
        $config = new Config('/nonexistent', $environment, 'Europe/Berlin', 'test');

        return AppFactory::create($config, $this->db(), $this->box(), $this->clock());
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function call(string $method, string $path, array $body = [], array $headers = [], array $cookies = [], string $ip = '10.0.0.5'): Response
    {
        $server = ['HTTP_HOST' => 'meridian.test', 'REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $request = Request::create('https://meridian.test' . $path, $method, [], $cookies, [], $server, $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR));

        return $this->kernel()->handle($request);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $cookies
     */
    private function callPlainHttp(string $method, string $path, array $body = [], array $cookies = []): Response
    {
        $server = ['HTTP_HOST' => 'meridian.test', 'REMOTE_ADDR' => '10.0.0.5', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://meridian.test'];
        $request = Request::create('http://meridian.test' . $path, $method, [], $cookies, [], $server, $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR));

        return $this->kernel()->handle($request);
    }

    /**
     * Liest einen verschachtelten Wert aus Antwort-Daten, ohne auf mixed zuzugreifen.
     */
    private static function dig(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    private function sessionCount(): int
    {
        $n = self::dig($this->db()->fetchOne('SELECT COUNT(*) AS n FROM sessions'), 'n');
        Assert::assertIsInt($n);

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true, 16, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function login(string $username, string $password = self::PASSWORD, string $ip = '10.0.0.5'): Response
    {
        return $this->call('POST', '/api/auth/login', ['username' => $username, 'password' => $password], ['Origin' => 'https://meridian.test'], [], $ip);
    }

    private function sessionCookie(Response $response, string $name = self::COOKIE): string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name && $cookie->getValue() !== null && $cookie->getValue() !== '') {
                return $cookie->getValue();
            }
        }
        Assert::fail('Kein Sitzungs-Cookie gesetzt.');
    }

    private function csrfOf(Response $response): string
    {
        $token = $this->json($response)['csrf_token'] ?? null;
        Assert::assertIsString($token);

        return $token;
    }
}
