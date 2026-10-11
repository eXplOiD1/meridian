<?php

declare(strict_types=1);

/**
 * Router für `php -S 127.0.0.1:<port>` in der Leak- und SSRF-Gesamtsuite (Phase 3, S11). Nur Loopback, nur Tests.
 * Er spiegelt jede Anfrage (Pfad, Query, Header, Body) zurück und trägt das Test-Geheimnis zusätzlich in Antwort-
 * Header, Fehlerseiten und Weiterleitungen. Jede Anfrage steht in der Treffer-Datei (LEAK_HIT_LOG), damit Tests
 * belegen können, dass ein gesperrtes Ziel nie erreicht wurde.
 *
 *   /echo/…          200, Antwort spiegelt die Anfrage
 *   /fail/…          500, Fehlerseite spiegelt die Anfrage
 *   /moved/…         302 auf /echo/… (gleicher Ursprung, Pfad und Query bleiben)
 *   /go/<b64url>/…   302 auf die dekodierte Adresse + Rest des Pfads + Query
 *   /go307/<b64url>/…  wie /go mit 307
 *   /slow/…          Antwort nach 3 s
 *   /status/NNN      Status NNN
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = is_string($uri) ? $uri : '/';
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$method = is_string($method) ? $method : '';

$log = getenv('LEAK_HIT_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, $method . ' ' . $uri . "\n", FILE_APPEND | LOCK_EX);
}

$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$query = parse_url($uri, PHP_URL_QUERY);
$query = is_string($query) ? '?' . $query : '';
$segments = explode('/', ltrim($path, '/'));
$first = $segments[0];
$rest = implode('/', array_slice($segments, 1));

$reflect = static function () use ($method, $uri): void {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Reflected: ' . $uri);
    foreach (getallheaders() as $name => $value) {
        // Wert des ersten benutzerdefinierten Headers auch als Antwort-Header spiegeln.
        if (strtolower((string) $name) === 'x-custom-token') {
            header('X-Reflected-Token: ' . (is_string($value) ? $value : ''));
        }
    }
    echo 'method=', $method, "\n", 'uri=', $uri, "\n";
    foreach (getallheaders() as $name => $value) {
        echo 'header ', strtolower((string) $name), ': ', is_string($value) ? $value : '', "\n";
    }
    echo 'body=', file_get_contents('php://input'), "\n";
    // Ein Wert, den nur der Server kennt, in der Form, die das Muster „token=“ erkennt.
    echo 'token=', getenv('LEAK_SERVER_SECRET') ?: 'none', "\n";
};

switch ($first) {
    case 'echo':
        $reflect();

        return;
    case 'fail':
        http_response_code(500);
        $reflect();

        return;
    case 'moved':
        http_response_code(302);
        header('Location: /echo/' . $rest . $query);

        return;
    case 'go':
    case 'go307':
        $target = base64_decode(strtr($segments[1] ?? '', '-_', '+/'), true);
        http_response_code($first === 'go' ? 302 : 307);
        header('Location: ' . ($target === false ? '/echo' : $target) . '/' . implode('/', array_slice($segments, 2)) . $query);

        return;
    case 'slow':
        sleep(3);
        $reflect();

        return;
    case 'status':
        http_response_code((int) ($segments[1] ?? '200'));
        echo 'status';

        return;
}

http_response_code(404);
echo 'nicht gefunden';
