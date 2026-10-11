<?php

declare(strict_types=1);

namespace Meridian\User;

/**
 * Eine Änderung hätte den letzten aktiven Administrator mit allen Rechten entfernt (ADR 0005, E3). Die
 * Transaktion wird zurückgerollt; der Kernel antwortet 409.
 */
final class LastAdminRemoved extends \RuntimeException
{
    public const MESSAGE = 'Mindestens ein aktiver Administrator mit allen Rechten muss bleiben. Zuerst einen anderen Benutzer zum Administrator machen.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
