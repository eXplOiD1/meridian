<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Eine von UrlPolicy geprüfte URL. Schema, Host und Port sind kein Geheimnis (Ziel, O2); Pfad und Query
 * können eines sein und sind deshalb privat, erscheinen nicht in var_dump/print_r/json_encode und die Klasse
 * ist nicht serialisierbar.
 *
 * Entsteht nur in UrlPolicy::parse(); der Konstruktor setzt geprüfte Teile voraus.
 */
final readonly class ParsedUrl implements \JsonSerializable
{
    /**
     * @internal nur UrlPolicy
     *
     * @param 'http'|'https' $scheme
     * @param string         $host  Kleinbuchstaben; IPv6 kanonisch und ohne Klammern
     * @param string         $path  '' oder mit '/' beginnend, roh (Prozentkodierung bleibt)
     * @param string|null    $query ohne '?', null = keine Query
     */
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public bool $isIpLiteral,
        #[\SensitiveParameter] private string $path,
        #[\SensitiveParameter] private ?string $query,
    ) {
    }

    public function isIpv6(): bool
    {
        return $this->isIpLiteral && str_contains($this->host, ':');
    }

    /** Host, wie er in einer URL steht (IPv6 in eckigen Klammern). */
    public function hostForUrl(): string
    {
        return $this->isIpv6() ? '[' . $this->host . ']' : $this->host;
    }

    public function isDefaultPort(): bool
    {
        return $this->port === ($this->scheme === 'https' ? 443 : 80);
    }

    /** Schema + Host (+ Port, wenn nicht Standard): das Ziel ohne jeden geheimen Teil. */
    public function origin(): string
    {
        return $this->scheme . '://' . $this->hostForUrl() . ($this->isDefaultPort() ? '' : ':' . $this->port);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(): ?string
    {
        return $this->query;
    }

    /** Die vollständige, normalisierte URL, die gesendet wird. Geheim. */
    public function toUrl(): string
    {
        return $this->origin() . $this->path . ($this->query === null ? '' : '?' . $this->query);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['origin' => $this->origin(), 'path' => '[verborgen]', 'query' => '[verborgen]'];
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return ['origin' => $this->origin()];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Eine URL mit möglichen Geheimnissen wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Eine URL mit möglichen Geheimnissen wird nicht deserialisiert.');
    }
}
