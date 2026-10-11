<?php

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Runner\Http\AddressPolicy;
use Meridian\Runner\Http\HostResolver;
use Meridian\Runner\Http\InfrastructureTargets;
use Meridian\Runner\Http\InternalTarget;
use Meridian\Runner\Http\InternalTargetSource;
use Meridian\Runner\Http\InvalidInternalTarget;
use Meridian\Runner\Http\ParsedUrl;
use Meridian\Runner\Http\TargetBlocked;
use Meridian\Runner\Http\TargetGuard;
use Meridian\Runner\Http\TargetUnresolvable;

/**
 * Löst das Ziel eines Kanals auf und prüft **jede** Adresse (ADR 0007 N7): öffentliche Adressen ja; private und Loopback
 * nur für genau diesen Host und Port (der Admin hat den Kanal bewusst angelegt, eine zweite Freigabe ist nicht nötig);
 * Link-local/Metadaten, reservierte Bereiche und Meridians eigene Infrastruktur nie. Liefert die geprüften Adressen, zu
 * denen verbunden wird (kein zweites Auflösen).
 */
final class ChannelNetwork
{
    public function __construct(
        private readonly HostResolver $resolver,
        private readonly InfrastructureTargets $infrastructure = new InfrastructureTargets(),
    ) {
    }

    /**
     * @return non-empty-list<string>
     *
     * @throws SendFailed
     */
    public function pin(string $host, int $port): array
    {
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        try {
            $release = InternalTarget::create($isIp ? InternalTarget::KIND_CIDR : InternalTarget::KIND_HOST, $isIp ? $host . (str_contains($host, ':') ? '/128' : '/32') : $host, $port, null);
        } catch (InvalidInternalTarget) {
            $release = null;
        }
        $source = new class($release) implements InternalTargetSource {
            public function __construct(private readonly ?InternalTarget $release)
            {
            }

            #[\Override]
            public function targetsFor(?int $categoryId): array
            {
                return $this->release === null ? [] : [$this->release];
            }
        };
        $guard = new TargetGuard($this->resolver, new AddressPolicy($source, $this->infrastructure));
        try {
            return $guard->pin(new ParsedUrl('https', $host, $port, $isIp, '', null), null)->ips;
        } catch (TargetBlocked | TargetUnresolvable) {
            throw new SendFailed(SendFailed::TARGET);
        }
    }
}
