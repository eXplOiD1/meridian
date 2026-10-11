<?php

declare(strict_types=1);

/**
 * Router für `php -S 127.0.0.1:<port>` in den Transport-Tests. Nur Loopback, nur für Tests.
 *
 *   /status/NNN              Status NNN, Text „status NNN“
 *   /echo                    Methode, Header und Body als Text zurück
 *   /redirect?code=N&to=URL  Weiterleitung
 *   /slow?ms=N               Antwort nach N ms
 *   /drip?ms=N               Header sofort, Body nach N ms
 *   /big?bytes=N             N Byte Text in Blöcken
 *   /binary                  Binärinhalt
 *   /bigheaders              Header über 32 KiB
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$query = [];
parse_str(is_string($queryString) ? $queryString : '', $query);
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$int = static fn (string $name, int $default): int => isset($query[$name]) && is_string($query[$name]) ? (int) $query[$name] : $default;

if (preg_match('~^/status/([1-5][0-9]{2})$~', $path, $m) === 1) {
    http_response_code((int) $m[1]);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'status ', $m[1];

    return;
}

switch ($path) {
    case '/echo':
        header('Content-Type: text/plain; charset=utf-8');
        echo 'method=', is_string($method) ? $method : '', "\n";
        foreach (getallheaders() as $name => $value) {
            echo 'header ', strtolower((string) $name), ': ', is_string($value) ? $value : '', "\n";
        }
        echo 'body=', file_get_contents('php://input'), "\n";

        return;
    case '/redirect':
        http_response_code($int('code', 302));
        header('Location: ' . (isset($query['to']) && is_string($query['to']) ? $query['to'] : '/status/200'));

        return;
    case '/slow':
        usleep($int('ms', 1000) * 1000);
        echo 'ok';

        return;
    case '/drip':
        header('Content-Type: text/plain');
        echo str_repeat(' ', 8);
        flush();
        usleep($int('ms', 1000) * 1000);
        echo 'ok';

        return;
    case '/big':
        header('Content-Type: text/plain');
        $left = $int('bytes', 1024);
        $block = str_repeat('a', 65536);
        while ($left > 0) {
            $n = min($left, 65536);
            echo substr($block, 0, $n);
            $left -= $n;
            if (connection_aborted() === 1) {
                return;
            }
        }

        return;
    case '/binary':
        header('Content-Type: application/octet-stream');
        echo "\x00\x01\x02\xFF\xFEbinär";

        return;
    case '/bigheaders':
        for ($i = 0; $i < 40; ++$i) {
            header('X-Fill-' . $i . ': ' . str_repeat('x', 1000));
        }
        echo 'ok';

        return;
}

http_response_code(404);
echo 'nicht gefunden';
