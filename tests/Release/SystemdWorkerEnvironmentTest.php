<?php

declare(strict_types=1);

namespace Meridian\Tests\Release;

use Meridian\Runner\JobType;
use Meridian\Schedule\WorkerSupervisor;
use PHPUnit\Framework\TestCase;

final class SystemdWorkerEnvironmentTest extends TestCase
{
    public function testWorkersKeepCredentialDirectoryButDropUnrelatedSecrets(): void
    {
        $env = ['CREDENTIALS_DIRECTORY' => '/run/credentials/meridian-worker-http.service', 'MERIDIAN_KEY_FILE' => '/run/credentials/meridian-worker-http.service/meridian_key', 'UNRELATED_PASSWORD' => 'must-not-inherit'];
        foreach ([JobType::Http, JobType::Shell] as $type) {
            $child = WorkerSupervisor::childEnvironment($env, $type);
            self::assertSame($env['CREDENTIALS_DIRECTORY'], $child['CREDENTIALS_DIRECTORY']);
            self::assertSame($env['MERIDIAN_KEY_FILE'], $child['MERIDIAN_KEY_FILE']);
            self::assertArrayNotHasKey('UNRELATED_PASSWORD', $child);
        }
    }
}
