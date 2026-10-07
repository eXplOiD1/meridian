<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Config;
use Meridian\Security\AccessDenied;
use Meridian\Security\SecretMasker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class Kernel
{
    /** @var array<string, callable(Request): Response> */
    private array $handlers = [];

    private RouteCollection $routes;

    public function __construct(
        private readonly Config $config,
        private readonly SecretMasker $masker,
    ) {
        $this->routes = new RouteCollection();
        $this->get('health', '/api/health', fn (Request $r): Response => new JsonResponse([
            'status' => 'ok',
            'version' => $this->config->version,
        ]));
    }

    /**
     * @param callable(Request): Response $handler
     * @param array<string, string>       $requirements Muster für Pfadparameter, z. B. ['path' => '.+']
     */
    public function get(string $name, string $path, callable $handler, array $requirements = []): void
    {
        $this->routes->add($name, new Route($path, requirements: $requirements, methods: ['GET']));
        $this->handlers[$name] = $handler;
    }

    /**
     * @param callable(Request): Response $handler
     */
    public function post(string $name, string $path, callable $handler): void
    {
        $this->routes->add($name, new Route($path, methods: ['POST']));
        $this->handlers[$name] = $handler;
    }

    public function handle(Request $request): Response
    {
        try {
            $matcher = new UrlMatcher($this->routes, (new RequestContext())->fromRequest($request));
            $match = $matcher->matchRequest($request);
            // Pfadparameter (z. B. {path}) stehen dem Handler als Attribute der Anfrage zur Verfügung.
            foreach (array_keys($match) as $key) {
                if (is_string($key) && !str_starts_with($key, '_')) {
                    $request->attributes->set($key, $match[$key]);
                }
            }
            $handler = isset($match['_route']) && is_string($match['_route']) ? ($this->handlers[$match['_route']] ?? null) : null;
            $response = $handler !== null ? $handler($request) : $this->error(404, 'Nicht gefunden.');
        } catch (ResourceNotFoundException) {
            $response = $this->error(404, 'Nicht gefunden.');
        } catch (MethodNotAllowedException) {
            $response = $this->error(405, 'Methode nicht erlaubt.');
        } catch (AccessDenied $e) {
            $response = $this->error(403, $e->getMessage());
        } catch (\Throwable $e) {
            // Im Betrieb nie Details zeigen. In dev nur maskiert.
            error_log($this->masker->mask($e::class . ': ' . $e->getMessage()));
            $response = $this->error(
                500,
                $this->config->isDev() ? $this->masker->mask($e->getMessage()) : 'Interner Fehler.',
            );
        }

        return SecurityHeaders::apply($response, !$this->config->isDev() && $request->isSecure());
    }

    private function error(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
