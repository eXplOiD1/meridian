<?php

declare(strict_types=1);

/**
 * Schnelltest der Sicherheits-Kernklassen OHNE Composer-Abhängigkeiten.
 * Für Umgebungen, in denen `composer install` (noch) nicht möglich ist.
 * Die vollständigen Tests laufen mit `composer test`.
 *
 *   php tests/smoke.php
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Meridian\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/Meridian/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Meridian\Database\Connection;
use Meridian\Database\Migrator;
use Meridian\Security\AccessControl;
use Meridian\Security\ApiToken;
use Meridian\Security\PasswordHasher;
use Meridian\Security\Permission;
use Meridian\Security\RoleGrant;
use Meridian\Security\SecretBox;
use Meridian\Security\SecretException;
use Meridian\Security\SecretMasker;
use Meridian\User\UserRepository;

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

echo "SecretBox\n";
$key = sodium_crypto_secretbox_keygen();
$box = new SecretBox($key);
$enc = $box->encrypt('https://x.test/cron.php?key=abc123def456');
$check('Rundreise', $box->decrypt($enc) === 'https://x.test/cron.php?key=abc123def456');
$check('Klartext nicht im Chiffrat', !str_contains($enc, 'abc123def456'));
$check('Schlüssel nicht in print_r', !str_contains(print_r($box, true), $key));
try {
    (new SecretBox(sodium_crypto_secretbox_keygen()))->decrypt($enc);
    $check('falscher Schlüssel abgelehnt', false);
} catch (SecretException) {
    $check('falscher Schlüssel abgelehnt', true);
}
try {
    serialize($box);
    $check('nicht serialisierbar', false);
} catch (\LogicException) {
    $check('nicht serialisierbar', true);
}

echo "SecretMasker\n";
$m = new SecretMasker();
$cases = [
    'GET https://x.test/cron.php?key=TESTKEY0000fake' => 'GET https://x.test/cron.php?key=••••',
    '/run?a=1&token=abcdef123456&b=2' => '/run?a=1&token=••••&b=2',
    'password=hunter2hunter2' => 'password=••••',
    'Authorization: Bearer eyJhbGciOi.abc.def' => 'Authorization: Bearer ••••',
    'X-Api-Key: 1234567890abcdef' => 'X-Api-Key: ••••',
    'https://alex:s3cr3t@nas.local/api' => 'https://alex:••••@nas.local/api',
    'Starting scan for user 1 out of 2' => 'Starting scan for user 1 out of 2',
];
foreach ($cases as $in => $expected) {
    $check('Muster: ' . substr($in, 0, 40), $m->mask($in) === $expected);
}
$m->remember('s3cr3t/value+x');
$out = $m->mask('a s3cr3t/value+x b ' . rawurlencode('s3cr3t/value+x') . ' c ' . base64_encode('s3cr3t/value+x'));
$check('bekanntes Geheimnis in allen Kodierungen', !str_contains($out, 's3cr3t') && substr_count($out, SecretMasker::MASK) === 3);

echo "Passwörter und Tokens\n";
$hasher = new PasswordHasher();
$hash = $hasher->hash('ein-langes-passwort');
$check('Passwort-Hash prüfbar', $hasher->verify('ein-langes-passwort', $hash) && !$hasher->verify('falsch-falsch-falsch', $hash));
$token = ApiToken::issue();
$check('Token nur als Hash', ApiToken::matches($token['plain'], $token['hash']) && !str_contains($token['hash'], $token['plain']));

echo "Rechte\n";
$acl = new AccessControl();
$op = [new RoleGrant('Operator', [Permission::RunJobs], ['Deuba24'])];
$check('Kategorie erlaubt', $acl->can($op, Permission::RunJobs, 'Deuba24'));
$check('fremde Kategorie verboten', !$acl->can($op, Permission::RunJobs, 'NAS'));
$check('ohne Grants alles verboten', !$acl->can([], Permission::ViewJobs));

echo "Datenbank\n";
$db = Connection::inMemory();
$migrator = new Migrator($db, dirname(__DIR__) . '/Meridian/migrations');
$check('Migration einmalig', $migrator->migrate() === ['0001_init.sql'] && $migrator->migrate() === []);
$users = new UserRepository($db, $hasher);
$check('hasUsers vor dem ersten Benutzer', !$users->hasUsers());
$id = $users->create('jana', 'Jana', 'ein-langes-passwort', 'Operator');
$check('hasUsers nach dem ersten Benutzer', $users->hasUsers());
$short = false;
try {
    $users->create('kurz', 'Kurz', '1234567', 'Operator');
} catch (\InvalidArgumentException) {
    $short = true;
}
$check('Passwort unter 8 Zeichen abgelehnt', $short);
$check('Passwort mit 8 Zeichen erlaubt', $users->create('acht', 'Acht', '12345678', 'Operator') > 0);
$grants = $users->grantsFor($id);
$check('Operator-Rechte geladen', count($grants) === 1 && in_array(Permission::RunJobs, $grants[0]->permissions, true)
    && !in_array(Permission::EditShellJobs, $grants[0]->permissions, true));
$row = $db->fetchOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $id]);
$check('kein Klartext-Passwort in der DB', is_array($row) && is_string($row['password_hash']) && !str_contains($row['password_hash'], 'ein-langes-passwort'));

echo PHP_EOL . ($failures === 0 ? "Alle Prüfungen bestanden.\n" : $failures . " Prüfung(en) fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
