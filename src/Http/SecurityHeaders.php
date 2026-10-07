<?php

declare(strict_types=1);

namespace Meridian\Http;

use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public static function apply(Response $response, bool $hsts = false): Response
    {
        $headers = $response->headers;
        $headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        if ($hsts) {
            // Nur über HTTPS und nicht in dev: sonst sperrt sich der Browser für HTTP-Zugriffe aus.
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        $headers->remove('X-Powered-By');

        return $response;
    }
}
