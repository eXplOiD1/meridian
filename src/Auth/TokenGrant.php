<?php

declare(strict_types=1);

namespace Meridian\Auth;

/** Ergebnis einer gültigen Token-Anmeldung: wer handelt, mit welchem Token, nur lesend oder nicht. */
final readonly class TokenGrant
{
    public function __construct(public int $userId, public int $tokenId, public bool $readOnly)
    {
    }
}
