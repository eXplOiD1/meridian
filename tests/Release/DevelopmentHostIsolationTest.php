<?php

declare(strict_types=1);

namespace Meridian\Tests\Release;

use Meridian\Runner\Shell\HostIsolation;
use PHPUnit\Framework\TestCase;

final class DevelopmentHostIsolationTest extends TestCase
{
    public function testContainerOverrideNeedsExplicitFlagAndDevelopmentEnvironment(): void
    {
        $markers = new \ReflectionProperty(HostIsolation::class, 'containerMarkers');
        foreach ([[], ['MERIDIAN_ENV' => 'prod'], ['MERIDIAN_ENV' => 'dev']] as $env) {
            self::assertSame(HostIsolation::DEFAULT_CONTAINER_MARKERS, $markers->getValue(HostIsolation::fromEnvironment($env)));
        }
        self::assertSame(HostIsolation::DEFAULT_CONTAINER_MARKERS, $markers->getValue(HostIsolation::fromEnvironment(['MERIDIAN_ENV' => 'prod'], true)));
        $dev = HostIsolation::fromEnvironment(['MERIDIAN_ENV' => 'dev'], true);
        self::assertSame([], $markers->getValue($dev));
        self::assertTrue((new \ReflectionProperty(HostIsolation::class, 'refuseRoot'))->getValue($dev));
    }

    public function testDevelopmentOverrideStillProtectsReadableSecrets(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::assertSame(HostIsolation::AS_ROOT, HostIsolation::fromEnvironment(['MERIDIAN_ENV' => 'dev'], true)->violation());
            return;
        }
        $file = tempnam(sys_get_temp_dir(), 'meridian-protected-');
        try {
            $dev = HostIsolation::fromEnvironment(['MERIDIAN_ENV' => 'dev', 'MERIDIAN_KEY_FILE' => $file], true);
            self::assertSame(HostIsolation::CAN_READ_SECRETS, $dev->violation());
        } finally {
            unlink($file);
        }
    }
}
