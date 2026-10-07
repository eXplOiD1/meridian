<?php

declare(strict_types=1);

use Meridian\Config;
use Meridian\Http\Kernel;
use Meridian\Security\SecretMasker;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string, string> $env */
$env = getenv();

$kernel = new Kernel(Config::fromEnvironment($env), new SecretMasker());
$kernel->handle(Request::createFromGlobals())->send();
