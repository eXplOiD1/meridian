<?php

declare(strict_types=1);

namespace Meridian\Tests\Support;

use Meridian\Auth\CsrfGuard;
use Meridian\Auth\SessionManager;
use Meridian\Job\CategoryRepository;
use Meridian\Job\JobRepository;
use Meridian\Job\JobValidator;
use Meridian\Security\CategoryScope;
use Meridian\Security\PasswordHasher;
use Meridian\Settings\Settings;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hilfen für Tests der Job-API (zusätzlich zu ApiHarness): Rollen mit und ohne Kategoriebeschränkung,
 * Kategorien A und B, Jobs in A, in B und ohne Kategorie.
 */
trait JobApiHarness
{
    use ApiHarness;

    private const ORIGIN = 'https://meridian.test';

    /** @var array<string, array{cookie: string, csrf: string}> */
    private array $sessions = [];

    /** @var array<string, int> */
    private array $jobIds = [];

    private static ?string $cachedHash = null;

    private static function passwordHash(): string
    {
        return self::$cachedHash ??= (new PasswordHasher())->hash(self::PASSWORD);
    }

    private function seedCategories(): void
    {
        $this->db()->execute("INSERT INTO categories (id, name) VALUES (1, 'A'), (2, 'B')");
    }

    /**
     * @return array{cookie: string, csrf: string}
     */
    private function as(string $who): array
    {
        if (isset($this->sessions[$who])) {
            return $this->sessions[$who];
        }
        [$role, $categories] = match ($who) {
            'admin' => ['Admin', null],
            'operator' => ['Operator', null],
            'operatorA' => ['Operator', [1]],
            'observer' => ['Beobachter', null],
            'observerA' => ['Beobachter', [1]],
            'adminA' => ['Admin', [1]],
            default => throw new \InvalidArgumentException('Unbekannte Rolle ' . $who),
        };
        // Direkt anlegen und die Sitzung ohne Anmeldung starten: Argon2id je Test würde die Suite um Minuten verlängern.
        $this->db()->execute(
            'INSERT INTO users (username, display_name, password_hash, created_at) VALUES (:u, :d, :h, :c)',
            ['u' => $who, 'd' => ucfirst($who), 'h' => self::passwordHash(), 'c' => '2026-10-07T00:00:00+00:00'],
        );
        $id = $this->db()->lastInsertId();
        $this->db()->execute(
            'INSERT INTO user_roles (user_id, role_id, all_categories) VALUES (:u, (SELECT id FROM roles WHERE name = :r), 1)',
            ['u' => $id, 'r' => $role],
        );
        if ($categories !== null) {
            $grant = self::dig($this->db()->fetchOne('SELECT id FROM user_roles WHERE user_id = :u', ['u' => $id]), 'id');
            Assert::assertIsInt($grant);
            $this->db()->execute('UPDATE user_roles SET all_categories = 0 WHERE id = :g', ['g' => $grant]);
            foreach ($categories as $category) {
                $this->db()->execute('INSERT INTO user_role_categories (user_role_id, category_id) VALUES (:g, :c)', ['g' => $grant, 'c' => $category]);
            }
        }
        $session = (new SessionManager($this->db(), $this->clock()))->start($id);

        return $this->sessions[$who] = ['cookie' => $session->token, 'csrf' => (new CsrfGuard())->tokenFor($session->token)];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function api(?string $who, string $method, string $path, array $body = [], bool $csrf = true): Response
    {
        $headers = [];
        $cookies = [];
        if ($who !== null) {
            $session = $this->as($who);
            $cookies = [self::COOKIE => $session['cookie']];
            if ($method !== 'GET') {
                $headers = ['Origin' => self::ORIGIN] + ($csrf ? ['X-CSRF-Token' => $session['csrf']] : []);
            }
        }

        return $this->call($method, $path, $body, $headers, $cookies);
    }

    private function validator(): JobValidator
    {
        return new JobValidator(new CategoryRepository($this->db()), new Settings($this->db(), $this->clock()), $this->clock());
    }

    private function jobRepository(): JobRepository
    {
        return new JobRepository($this->db(), $this->box(), $this->clock());
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function jobInput(array $override = []): array
    {
        return $override + [
            'name' => 'Job',
            'type' => 'http',
            'category_id' => null,
            'cron' => '*/5 * * * *',
            'timezone' => 'Europe/Berlin',
            'request' => ['url' => 'https://img.example.org/tools/api/cron.php?key=' . self::testSecret('query')],
        ];
    }

    private static function testSecret(string $part): string
    {
        return 'TEST-SECRET-do-not-use-' . $part . '-' . strrev($part) . '9';
    }

    /**
     * Legt je einen Job in A, in B und ohne Kategorie an (direkt über das Repository, nicht über die API).
     */
    private function seedJobs(): void
    {
        $this->seedCategories();
        $this->as('admin'); // der erste Benutzer (Nr. 1) ist der Besitzer der Jobs
        foreach (['JA' => 1, 'JB' => 2, 'JN' => null] as $name => $category) {
            $draft = $this->validator()->validate(self::jobInput(['name' => $name, 'category_id' => $category]), CategoryScope::everything(), null);
            $this->jobIds[$name] = $this->jobRepository()->insert($draft, 1);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jobsOf(Response $response): array
    {
        $jobs = $this->json($response)['jobs'] ?? null;
        Assert::assertIsArray($jobs);
        $out = [];
        foreach ($jobs as $job) {
            Assert::assertIsArray($job);
            /** @var array<string, mixed> $job */
            $out[] = $job;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $jobs
     *
     * @return list<string>
     */
    private static function namesOf(array $jobs): array
    {
        $names = array_map(static fn (array $job): string => is_string($job['name'] ?? null) ? $job['name'] : '', $jobs);
        sort($names);

        return $names;
    }

    /**
     * Wie api(), aber mit rohem Anfragekörper (für ungültiges JSON, Übergröße, Tiefe) und frei wählbaren Headern.
     *
     * @param array<string, string> $headers
     */
    private function rawApi(?string $who, string $method, string $path, string $content, ?array $headers = null): Response
    {
        $server = ['HTTP_HOST' => 'meridian.test', 'REMOTE_ADDR' => '10.0.0.5', 'CONTENT_TYPE' => 'application/json'];
        $cookies = [];
        if ($who !== null) {
            $session = $this->as($who);
            $cookies = [self::COOKIE => $session['cookie']];
            $headers ??= ['Origin' => self::ORIGIN, 'X-CSRF-Token' => $session['csrf']];
        }
        foreach ($headers ?? [] as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->kernel()->handle(\Symfony\Component\HttpFoundation\Request::create('https://meridian.test' . $path, $method, [], $cookies, [], $server, $content));
    }

    /**
     * Antworttext mit unmaskiertem Unicode und Schrägstrichen, damit Vergleiche lesbar bleiben.
     */
    private function plain(Response $response): string
    {
        $content = (string) $response->getContent();
        if ($content === '') {
            return '';
        }

        return json_encode(json_decode($content, true, 32, JSON_THROW_ON_ERROR), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Das Geheimnis darf in keiner Schreibweise vorkommen: roh, URL-kodiert, base64, als JSON-Text.
     *
     * @param list<string> $secrets
     */
    private static function assertNoSecret(string $text, array $secrets, string $where): void
    {
        foreach ($secrets as $secret) {
            foreach ([$secret, rawurlencode($secret), urlencode($secret), base64_encode($secret), (string) json_encode($secret), (string) json_encode($secret, JSON_UNESCAPED_SLASHES)] as $form) {
                Assert::assertStringNotContainsString($form, $text, $where);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rawJob(int $id): array
    {
        $row = $this->db()->fetchOne('SELECT * FROM jobs WHERE id = :id', ['id' => $id]);
        Assert::assertIsArray($row);

        return $row;
    }

    private function jobCount(): int
    {
        $n = self::dig($this->db()->fetchOne('SELECT COUNT(*) AS n FROM jobs'), 'n');
        Assert::assertIsInt($n);

        return $n;
    }

    /**
     * @return list<string>
     */
    private function auditActions(): array
    {
        $out = [];
        foreach ($this->db()->fetchAll('SELECT action FROM audit_log ORDER BY id') as $row) {
            $out[] = is_string($row['action'] ?? null) ? $row['action'] : '';
        }

        return $out;
    }
}
