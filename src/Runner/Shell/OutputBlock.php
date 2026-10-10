<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Runner\LiveStream;
use Meridian\Security\Sealed;

/**
 * Ein roher Ausgabeblock (stdout oder stderr), noch **nicht** maskiert. Die Bytes liegen versiegelt: keine
 * Darstellung zeigt sie (docs/decisions/0004 §5.1).
 */
final class OutputBlock implements \JsonSerializable
{
    /** @var Sealed<string> */
    private readonly Sealed $bytes;

    private readonly int $length;

    public function __construct(public readonly LiveStream $stream, #[\SensitiveParameter] string $bytes)
    {
        $this->bytes = new Sealed($bytes);
        $this->length = strlen($bytes);
    }

    public function bytes(): string
    {
        return $this->bytes->open();
    }

    public function length(): int
    {
        return $this->length;
    }

    /**
     * @return array<string, int|string>
     */
    public function __debugInfo(): array
    {
        return ['stream' => $this->stream->value, 'bytes' => $this->length];
    }

    /**
     * @return array<string, int|string>
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
        throw new \LogicException('Eine Laufausgabe wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Eine Laufausgabe wird nicht deserialisiert.');
    }
}
