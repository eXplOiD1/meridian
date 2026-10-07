<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * Eine Freigabe eines internen Ziels ist ungültig oder nie freigebbar. `field` ist `kind`, `value` oder `port`.
 */
final class InvalidInternalTarget extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
