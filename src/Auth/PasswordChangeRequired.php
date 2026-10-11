<?php

declare(strict_types=1);

namespace Meridian\Auth;

/**
 * Die Sitzung gehört einem Benutzer mit offenem Pflicht-Passwortwechsel (Einmalpasswort, ADR 0005 E6). Erlaubt sind
 * nur `GET /api/auth/me`, `POST /api/auth/password` und `POST /api/auth/logout`; alles andere → 403.
 */
final class PasswordChangeRequired extends \RuntimeException
{
    public const MESSAGE = 'Bitte zuerst ein eigenes Passwort setzen.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
