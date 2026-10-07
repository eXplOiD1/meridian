<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\AuditLog;
use Meridian\Auth\AuthService;
use Meridian\Auth\Clock;
use Meridian\Auth\CsrfGuard;
use Meridian\Auth\LoginThrottle;
use Meridian\Auth\SessionManager;
use Meridian\Auth\SystemClock;
use Meridian\Auth\TwoFactor;
use Meridian\Config;
use Meridian\Database\Connection;
use Meridian\Security\PasswordHasher;
use Meridian\Security\SecretBox;
use Meridian\Security\SecretMasker;
use Meridian\User\UserRepository;

/**
 * Baut den Kernel mit allen Abhängigkeiten zusammen (eine Stelle für die Verdrahtung).
 */
final class AppFactory
{
    public static function create(Config $config, Connection $db, SecretBox $box, ?Clock $clock = null): Kernel
    {
        $clock ??= new SystemClock();
        $masker = new SecretMasker();
        $hasher = new PasswordHasher();
        $users = new UserRepository($db, $hasher);
        $sessions = new SessionManager($db, $clock);
        $twoFactor = new TwoFactor($db, $box, $hasher, $clock);

        $auth = new AuthService(
            $users,
            $hasher,
            $sessions,
            new LoginThrottle($db, $clock),
            new AuditLog($db, $clock, $masker),
            $clock,
            $twoFactor,
        );

        $kernel = new Kernel($config, $masker);
        (new AuthController($config, $auth, $sessions, new CsrfGuard(), $users, $clock, $twoFactor))->register($kernel);

        return $kernel;
    }
}
