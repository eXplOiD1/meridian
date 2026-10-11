<?php

declare(strict_types=1);

namespace Meridian\User;

/**
 * Zustand eines Kontos in der Verwaltung (ADR 0005, §4.4). Gelöscht (Soft-Delete) ist immer auch inaktiv.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Deleted = 'deleted';
}
