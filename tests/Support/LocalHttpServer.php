<?php

declare(strict_types=1);

namespace Meridian\Tests\Support;

/**
 * Startet `php -S 127.0.0.1:<freier Port>` mit dem Test-Router. Nur Loopback; Tests geben Loopback mit genau
 * diesem Port frei, wenn sie den Runner gegen ihn laufen lassen.
 */
final class LocalHttpServer
{
    /** @var resource|null */
    private $process = null;

    public readonly int $port;

    public function __construct()
    {
        $this->port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/http_test_server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'w'], 2 => ['file', (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'w']],
            $pipes,
            null,
            ['PATH' => '/usr/bin:/bin', 'PHP_CLI_SERVER_WORKERS' => '4'],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Test-Server nicht startbar.');
        }
        $this->process = $process;
        for ($i = 0; $i < 100; ++$i) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        $this->stop();
        throw new \RuntimeException('Test-Server antwortet nicht.');
    }

    public function url(string $path): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, SIGKILL);
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function __destruct()
    {
        $this->stop();
    }

    /** Ein Port, auf dem gerade nichts lauscht. */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException('Kein freier Port.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
