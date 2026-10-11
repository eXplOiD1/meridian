<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Network;

use Meridian\Tests\Support\ApiHarness;
use PHPUnit\Framework\TestCase;

final class InternalTargetUpdateTest extends TestCase
{
    use ApiHarness;

    private const PATH = '/api/settings/internal-targets';
    private const TARGET = ['kind' => 'cidr', 'value' => '192.168.0.0/24', 'port' => 8080, 'category_id' => null, 'note' => 'TEST-NOTE'];

    public function testUpdatePreservesIdentityAndCreationAndAudits(): void
    {
        $this->users()->create('admin', 'Admin', self::PASSWORD, 'admin');
        $login = $this->login('admin');
        $cookies = [self::COOKIE => $this->sessionCookie($login)];
        $headers = ['Origin' => 'https://meridian.test', 'X-CSRF-Token' => $this->csrfOf($login)];
        $created = $this->json($this->call('POST', self::PATH, self::TARGET, $headers, $cookies));
        $target = $created['target'];
        self::assertIsArray($target);
        $id = $target['id'];
        self::assertIsInt($id);
        $this->db()->execute("INSERT INTO categories (id, name) VALUES (1, 'TEST-CATEGORY')");
        $body = ['kind' => 'host', 'value' => 'nextcloud', 'port' => 443, 'category_id' => 1, 'note' => 'TEST-UPDATED'];
        $response = $this->call('PUT', self::PATH . '/' . $id, $body, $headers, $cookies);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $updated = $this->json($response)['target'];
        self::assertIsArray($updated);
        self::assertSame($id, $updated['id']);
        self::assertSame($target['created_at'], $updated['created_at']);
        self::assertSame($target['created_by'], $updated['created_by']);
        $list = $this->json($this->call('GET', self::PATH, [], [], $cookies))['targets'];
        self::assertIsArray($list);
        self::assertSame($updated, $list[0]);
        self::assertSame('nextcloud', $updated['value']);
        self::assertSame(443, $updated['port']);
        self::assertSame('TEST-UPDATED', $updated['note']);
        self::assertSame(['id' => 1, 'name' => 'TEST-CATEGORY'], $updated['category']);
        self::assertSame('network.internal_target_updated', $this->db()->fetchOne("SELECT action FROM audit_log WHERE action = 'network.internal_target_updated'")['action']);
        self::assertSame(404, $this->call('PUT', self::PATH . '/999', $body, $headers, $cookies)->getStatusCode());
        self::assertSame(422, $this->call('PUT', self::PATH . '/' . $id, array_replace($body, ['value' => '169.254.0.0/16', 'kind' => 'cidr']), $headers, $cookies)->getStatusCode());
        self::assertSame($updated, $this->json($this->call('GET', self::PATH, [], [], $cookies))['targets'][0]);
        $this->call('POST', self::PATH, self::TARGET, $headers, $cookies);
        self::assertSame(422, $this->call('PUT', self::PATH . '/' . $id, self::TARGET, $headers, $cookies)->getStatusCode());
        self::assertSame($updated, $this->json($this->call('GET', self::PATH, [], [], $cookies))['targets'][0]);
    }

    public function testUpdateRequiresSessionCsrfAndUnrestrictedPermission(): void
    {
        self::assertSame(401, $this->call('PUT', self::PATH . '/1', self::TARGET)->getStatusCode());
        foreach (['operator', 'observer', 'admin'] as $role) {
            $this->users()->create($role, ucfirst($role), self::PASSWORD, \Meridian\User\BuiltinRole::from($role));
            $login = $this->login($role);
            $cookies = [self::COOKIE => $this->sessionCookie($login)];
            $headers = ['Origin' => 'https://meridian.test', 'X-CSRF-Token' => $this->csrfOf($login)];
            self::assertSame(403, $this->call('PUT', self::PATH . '/1', self::TARGET, ['Origin' => 'https://meridian.test'], $cookies)->getStatusCode());
            if ($role !== 'admin') {
                self::assertSame(403, $this->call('PUT', self::PATH . '/1', self::TARGET, $headers, $cookies)->getStatusCode());
            }
            self::assertSame(403, $this->call('PUT', self::PATH . '/1', self::TARGET, array_replace($headers, ['Origin' => 'https://evil.test']), $cookies)->getStatusCode());
        }
        self::assertSame(0, $this->db()->fetchOne('SELECT COUNT(*) AS n FROM http_internal_targets')['n']);
    }
}

