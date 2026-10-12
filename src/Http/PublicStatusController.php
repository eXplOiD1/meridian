<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\Clock;
use Meridian\Config;
use Meridian\Security\SecretMasker;
use Meridian\Status\StatusPageRenderer;
use Meridian\Status\StatusPageRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /status/{slug}` — öffentliche Statusseite (ADR 0012), ohne Anmeldung: der Slug (120 Bit Zufall) ist der Zugang.
 * Unbekannt, deaktiviert und falsch geformt antworten gleich mit 404. Die Antwort enthält nur Titel, Anzeigenamen, Zustand
 * und Zeitpunkt (alles maskiert), ist nicht indexierbar (`X-Robots-Tag`) und höchstens 30 s alt. Dazu `/status/status.css`.
 */
final class PublicStatusController
{
    public function __construct(
        private readonly StatusPageRepository $pages,
        private readonly StatusPageRenderer $renderer,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly SecretMasker $masker,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('status_css', '/status/status.css', $this->stylesheet(...));
        $kernel->get('status_public', '/status/{slug}', $this->show(...), ['slug' => '[a-z2-7]{24}']);
    }

    public function show(#[\SensitiveParameter] Request $request): Response
    {
        $view = $this->pages->publicView($request->attributes->getString('slug'));
        if ($view === null) {
            return self::notFound();
        }
        $html = $this->renderer->render(
            $view['title'],
            $view['items'],
            new \DateTimeZone($this->config->timezone === '' ? 'UTC' : $this->config->timezone),
            $this->clock->now(),
            fn (string $text): string => $this->masker->mask($text),
        );

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=30',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    public function stylesheet(): Response
    {
        return new Response(StatusPageRenderer::stylesheet(), 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private static function notFound(): Response
    {
        return new Response('Nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
