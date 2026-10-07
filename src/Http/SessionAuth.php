<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\Session;
use Meridian\Auth\SessionManager;
use Meridian\Config;
use Symfony\Component\HttpFoundation\Request;

/**
 * Liest die Sitzung aus dem Cookie. Eine Stelle für alle Endpunkte, damit keiner eine eigene
 * (womöglich laxere) Prüfung baut.
 */
final class SessionAuth
{
    public function __construct(
        private readonly Config $config,
        private readonly SessionManager $sessions,
    ) {
    }

    public function cookieName(): string
    {
        // Das Präfix __Host- erzwingt Secure, Path=/ und kein Domain-Attribut. In dev (HTTP) nicht möglich.
        return $this->config->isDev() ? 'meridian_session' : '__Host-meridian_session';
    }

    public function authenticate(Request $request): ?Session
    {
        $token = $request->cookies->get($this->cookieName());

        return is_string($token) ? $this->sessions->resolve($token) : null;
    }
}
