<?php

declare(strict_types=1);

namespace Meridian\Auth;

/**
 * Eine eigene Sitzung für die Übersicht in „Mein Konto“ (ADR 0005, E10). Nie mit `token_hash`.
 * `userAgent` ist beim Anlegen gekürzt und von Steuerzeichen befreit; die Antwort maskiert ihn zusätzlich.
 * Nur für den Inhaber: Admins sehen bei fremden Benutzern nur die Anzahl (B8).
 */
final readonly class SessionView
{
    public function __construct(
        public int $id,
        public string $createdAt,
        public string $lastSeenAt,
        public string $expiresAt,
        public bool $current,
        public ?string $userAgent,
        public ?string $clientIp,
    ) {
    }
}
