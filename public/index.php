<?php

declare(strict_types=1);

use Meridian\Config;
use Meridian\Database\Connection;
use Meridian\Http\AppFactory;
use Meridian\Security\KeyLoader;
use Meridian\Security\SecretBox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

// Auch ohne php.ini (z. B. systemd): Fehler und Warnungen nie in die Antwort schreiben.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string, string> $env */
$env = getenv();

try {
    $config = Config::fromEnvironment($env);
    if ($config->trustedProxies !== []) {
        // Client-IP und Host nur von konfigurierten Reverse-Proxys glauben.
        Request::setTrustedProxies(
            $config->trustedProxies,
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_HOST,
        );
    }

    $kernel = AppFactory::create($config, Connection::open($config->databasePath()), new SecretBox(KeyLoader::load($env)));
    $kernel->handle(Request::createFromGlobals())->send();
} catch (\Throwable $e) {
    // Fehler vor dem Kernel (Konfiguration, Datenbank): im Betrieb nie Details nach außen.
    error_log($e::class . ': ' . $e->getMessage());
    (new JsonResponse(['error' => 'Interner Fehler.'], 500))->send();
}
