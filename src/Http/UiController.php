<?php

declare(strict_types=1);

namespace Meridian\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liefert die gebaute Oberfläche (frontend/ -> npm run build) unter /app/ aus.
 *
 * Bewusst über PHP statt über den Webserver: So läuft jede Antwort, auch Skripte, Stile und Schriften,
 * durch den Kernel und bekommt die Sicherheits-Header (CSP, nosniff, HSTS). Die Dateien liegen außerhalb
 * des Webroots (public/), damit der Webserver sie nie selbst und ohne Header ausliefern kann.
 */
final class UiController
{
    /** @var array<string, string> */
    private const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'woff2' => 'font/woff2',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
    ];

    private readonly string $root;

    public function __construct(string $uiDir)
    {
        $real = realpath($uiDir);
        $this->root = $real === false ? '' : $real;
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('ui_root', '/', fn (): Response => new RedirectResponse('/app/', 302));
        $kernel->get('ui_app', '/app', fn (): Response => new RedirectResponse('/app/', 302));
        $kernel->get('ui_index', '/app/', fn (Request $request): Response => $this->serve($request, 'index.html'));
        $kernel->get('ui_file', '/app/{path}', fn (Request $request): Response => $this->serve($request, self::pathOf($request)), requirements: ['path' => '.+']);
    }

    private static function pathOf(Request $request): string
    {
        return self::textOrEmpty($request->attributes->get('path'));
    }

    private static function textOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function serve(Request $request, string $path): Response
    {
        if ($this->root === '') {
            return new JsonResponse(['error' => 'Die Oberfläche ist nicht gebaut. Docker: die compose.yaml auf den aktuellen Stand bringen (das Dockerfile steht darin) und neu bauen. Lokal: im Ordner frontend/ „npm ci && npm run build“ ausführen und MERIDIAN_UI_DIR=frontend/dist setzen.'], 404);
        }

        $file = $this->resolve($path);
        if ($file === null) {
            return new JsonResponse(['error' => 'Nicht gefunden.'], 404);
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $content = file_get_contents($file);
        if ($content === false) {
            return new JsonResponse(['error' => 'Nicht gefunden.'], 404);
        }

        $response = new Response($content, 200, ['Content-Type' => self::TYPES[$extension] ?? 'application/octet-stream']);
        // Dateien mit Hash im Namen (assets/) und Schriften ändern sich nie; die Startseite immer neu prüfen.
        $immutable = str_starts_with($path, 'assets/') || str_starts_with($path, 'fonts/');
        $response->headers->set('Cache-Control', $immutable ? 'public, max-age=31536000, immutable' : 'no-cache');
        $response->setEtag(hash('sha256', $content));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Pfad auf eine Datei im Oberflächen-Ordner abbilden. Nie ein Pfad aus der Anfrage direkt öffnen:
     * feste Zeichenklasse, keine Verzeichniswechsel, Endung aus einer Allowlist, Ergebnis muss unter dem Ordner liegen.
     */
    private function resolve(string $path): ?string
    {
        if (preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $path) !== 1 || str_contains($path, '..')) {
            return null;
        }
        if (!array_key_exists(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::TYPES)) {
            return null;
        }

        $real = realpath($this->root . '/' . $path);
        if ($real === false) {
            return null;
        }
        if (!str_starts_with($real, $this->root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($real) ? $real : null;
    }
}
