<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Ein geprüftes Ziel für genau einen Hop: die URL und die Adressen, zu denen verbunden werden darf
 * (`CURLOPT_RESOLVE`). Es zählt nur diese Auflösung; eine zweite (DNS-Rebinding) wird nie benutzt.
 */
final readonly class PinnedTarget
{
    /**
     * @internal nur TargetGuard
     *
     * @param non-empty-list<string> $ips kanonische Textform
     */
    public function __construct(
        public ParsedUrl $url,
        public array $ips,
    ) {
    }
}
