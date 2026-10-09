<?php

declare(strict_types=1);

use Meridian\Config;
use Meridian\Database\Connection;
use Meridian\Http\AppFactory;
use Meridian\Http\ClientIp;
use Meridian\Http\ErrorLog;
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
    // Client-IP und Host nur von konfigurierten Reverse-Proxys glauben (leer = niemandem).
    ClientIp::trust($config);

    $kernel = AppFactory::create($config, Connection::open($config->databasePath()), new SecretBox(KeyLoader::load($env)));
    $kernel->handle(Request::createFromGlobals())->send();
} catch (\Throwable $e) {
    // Fehler vor dem Kernel (Konfiguration, Datenbank): nie Details nach außen, ins Log nie die Meldung.
    ErrorLog::unexpected($e, 'Start');
    (new JsonResponse(['error' => 'Interner Fehler.'], 500))->send();
}
