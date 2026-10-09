<?php

declare(strict_types=1);

namespace Meridian\Auth;

use Symfony\Component\HttpFoundation\Request;

/**
 * CSRF-Schutz für ändernde Anfragen: ein an die Sitzung gebundenes Token im Header
 * X-CSRF-Token und zusätzlich die Herkunft (Origin bzw. Referer) gegen den eigenen Host.
 */
final class CsrfGuard
{
    public const HEADER = 'X-CSRF-Token';

    /**
     * Das Token wird aus der Sitzungs-ID abgeleitet, muss also nicht gespeichert werden und ist
     * nur mit gültiger Sitzung gültig.
     */
    public function tokenFor(#[\SensitiveParameter] string $sessionToken): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'meridian-csrf-v1', $sessionToken, true)), '+/', '-_'), '=');
    }

    public function check(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] string $sessionToken): bool
    {
        $given = $request->headers->get(self::HEADER);
        if ($given === null || !hash_equals($this->tokenFor($sessionToken), $given)) {
            return false;
        }

        return $this->originMatches($request);
    }

    /**
     * Herkunftsprüfung ohne Sitzung (Anmeldung): fehlt Origin und Referer, wird abgelehnt.
     */
    public function originMatches(#[\SensitiveParameter] Request $request): bool
    {
        $source = $request->headers->get('Origin');
        if ($source === null || $source === '' || $source === 'null') {
            $source = $request->headers->get('Referer');
        }
        if ($source === null || $source === '') {
            return false;
        }

        $host = parse_url($source, PHP_URL_HOST);

        return is_string($host) && strtolower($host) === strtolower($request->getHost());
    }
}
