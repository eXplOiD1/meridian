<?php

declare(strict_types=1);

namespace Meridian\Tests\Support;

use Meridian\Runner\Http\HttpRunSettings;
use Meridian\Settings\ResponseStorage;

/**
 * Globale Einstellungen für Runner-Tests; zählt, wie oft gelesen wurde (frisch je Lauf).
 */
final class FakeRunSettings implements HttpRunSettings
{
    public int $reads = 0;

    public function __construct(public int $maxTimeout = 300, public ResponseStorage $storage = ResponseStorage::Off)
    {
    }

    #[\Override]
    public function maxTimeoutSeconds(): int
    {
        ++$this->reads;

        return $this->maxTimeout;
    }

    public function hidesHost(): bool { return false; }

    #[\Override]
    public function responseStorage(): ResponseStorage
    {
        return $this->storage;
    }
}
