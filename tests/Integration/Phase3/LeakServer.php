<?php

declare(strict_types=1);

namespace Meridian\Tests\Integration\Phase3;

use Meridian\Tests\Support\LocalHttpServer;

/**
 * `php -S 127.0.0.1:<freier Port>` mit dem Router der Leak-Suite. Zählt Treffer über eine Datei: ein gesperrtes
 * Ziel muss null Treffer haben. Nur Loopback.
 */
final class LeakServer
{
    /** @var resource|null */
    private $process = null;

    public readonly int $port;

    private readonly string $hitLog;

    public function __construct(string $serverSecret = 'none')
    {
        $this->port = LocalHttpServer::freePort();
        $log = tempnam(sys_get_temp_dir(), 'meridian-hits-');
        if ($log === false) {
            throw new \RuntimeException('Treffer-Datei nicht anlegbar.');
        }
        $this->hitLog = $log;
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/leak_router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PATH' => '/usr/bin:/bin', 'PHP_CLI_SERVER_WORKERS' => '4', 'LEAK_HIT_LOG' => $this->hitLog, 'LEAK_SERVER_SECRET' => $serverSecret],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Test-Server nicht startbar.');
        }
        $this->process = $process;
        for ($i = 0; $i < 100; ++$i) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                // Der Verbindungstest zählt nicht als Treffer (kein HTTP gesprochen); sicherheitshalber leeren.
                file_put_contents($this->hitLog, '');

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

    /** @return list<string> */
    public function hits(): array
    {
        $content = is_file($this->hitLog) ? (string) file_get_contents($this->hitLog) : '';

        return $content === '' ? [] : explode("\n", rtrim($content, "\n"));
    }

    public function hitCount(): int
    {
        return count($this->hits());
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, SIGKILL);
            proc_close($this->process);
        }
        $this->process = null;
        if (is_file($this->hitLog)) {
            unlink($this->hitLog);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
