<?php

declare(strict_types=1);

namespace Meridian\Tests\Release;

use Meridian\Tests\Support\ApiHarness;
use PHPUnit\Framework\TestCase;

final class RuntimeSettingsApiTest extends TestCase
{
    use ApiHarness;

    public function testRuntimeSettingValidationPersistenceAndExistingHistory(): void
    {
        $this->users()->create('admin', 'Admin', self::PASSWORD, 'admin');
        $login = $this->login('admin');
        $cookies = [self::COOKIE => $this->sessionCookie($login)];
        $headers = ['Origin' => 'https://meridian.test', 'X-CSRF-Token' => $this->csrfOf($login)];
        $input = ['name' => 'Runtime test', 'type' => 'http', 'cron' => '*/5 * * * *', 'timezone' => 'UTC', 'request' => ['url' => 'https://example.org/test']];
        $created = $this->call('POST', '/api/jobs', $input, $headers, $cookies);
        self::assertSame(201, $created->getStatusCode(), $created->getContent());
        $job = $this->json($created)['job'];
        self::assertNull($job['notify']['runtime_warn_percent']);
        $id = $job['id'];
        $this->db()->execute("INSERT INTO runs (job_id, trigger, status, finished_at, duration_ms) VALUES (:id, 'schedule', 'ok', '2026-10-07T08:00:00+00:00', 9000)", ['id' => $id]);
        $url = '/api/jobs/' . $id;
        foreach ([109, 1001, true, '200', 200.5] as $invalid) {
            $response = $this->call('PUT', $url, ['notify' => ['runtime_warn_percent' => $invalid]], $headers, $cookies);
            self::assertSame(422, $response->getStatusCode());
            self::assertArrayHasKey('notify.runtime_warn_percent', $this->json($response)['fields']);
        }
        $updated = $this->call('PUT', $url, ['notify' => ['runtime_warn_percent' => 200]], $headers, $cookies);
        self::assertSame(200, $updated->getStatusCode(), $updated->getContent());
        self::assertSame(200, $this->json($updated)['job']['notify']['runtime_warn_percent']);
        self::assertSame(1, $this->db()->fetchOne('SELECT runtime_checked FROM runs')['runtime_checked'], 'Enabling must not send old warnings');
        self::assertSame(200, $this->json($this->call('GET', $url, [], [], $cookies))['job']['notify']['runtime_warn_percent']);
        $disabled = $this->call('PUT', $url, ['notify' => ['runtime_warn_percent' => null]], $headers, $cookies);
        self::assertSame(200, $disabled->getStatusCode());
        self::assertNull($this->json($disabled)['job']['notify']['runtime_warn_percent']);
    }
}
