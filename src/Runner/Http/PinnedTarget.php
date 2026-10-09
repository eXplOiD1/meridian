<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Ein geprüftes Ziel für genau einen Hop: die URL und die Adressen, zu denen verbunden werden darf
 * (`CURLOPT_RESOLVE`). Es zählt nur diese Auflösung; eine zweite (DNS-Rebinding) wird nie benutzt.
 *
 * Pfad und Query der URL sind in {@see ParsedUrl} versiegelt; Darstellungen zeigen nur Ursprung und Adressen,
 * die Klasse ist nicht serialisierbar.
 */
final readonly class PinnedTarget implements \JsonSerializable
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

    /**
     * @return array{origin: string, ips: list<string>}
     */
    public function __debugInfo(): array
    {
        return ['origin' => $this->url->origin(), 'ips' => $this->ips];
    }

    /**
     * @return array{origin: string, ips: list<string>}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Ein Ziel mit möglichen Geheimnissen wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Ein Ziel mit möglichen Geheimnissen wird nicht deserialisiert.');
    }
}
