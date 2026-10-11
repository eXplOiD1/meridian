<?php

declare(strict_types=1);

namespace Meridian\Monitor;

/**
 * Art einer Überwachung: `heartbeat` wartet auf eingehende Pings (ADR 0011), die anderen prüft Meridian selbst (ADR 0018).
 */
enum MonitorKind: string
{
    case Heartbeat = 'heartbeat';
    case Http = 'http';
    case Tcp = 'tcp';
    case Ping = 'ping';

    public function isActive(): bool
    {
        return $this !== self::Heartbeat;
    }
}
