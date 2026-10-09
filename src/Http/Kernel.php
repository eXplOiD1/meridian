<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\PasswordChangeRequired;
use Meridian\Auth\TooManyAttempts;
use Meridian\Config;
use Meridian\Security\AccessDenied;
use Meridian\Security\SecretMasker;
use Meridian\User\LastAdminRemoved;
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
        $this->get('health', '/api/health', fn (#[\SensitiveParameter] Request $r): Response => new JsonResponse([
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
        $this->add('GET', $name, $path, $handler, $requirements);
    }

    /**
     * @param callable(Request): Response $handler
     * @param array<string, string>       $requirements
     */
    public function post(string $name, string $path, callable $handler, array $requirements = []): void
    {
        $this->add('POST', $name, $path, $handler, $requirements);
    }

    /**
     * @param callable(Request): Response $handler
     * @param array<string, string>       $requirements
     */
    public function put(string $name, string $path, callable $handler, array $requirements = []): void
    {
        $this->add('PUT', $name, $path, $handler, $requirements);
    }

    /**
     * @param callable(Request): Response $handler
     * @param array<string, string>       $requirements
     */
    public function delete(string $name, string $path, callable $handler, array $requirements = []): void
    {
        $this->add('DELETE', $name, $path, $handler, $requirements);
    }

    /**
     * @param callable(Request): Response $handler
     * @param array<string, string>       $requirements
     */
    private function add(string $method, string $name, string $path, callable $handler, array $requirements): void
    {
        $this->routes->add($name, new Route($path, requirements: $requirements, methods: [$method]));
        $this->handlers[$name] = $handler;
    }

    public function handle(#[\SensitiveParameter] Request $request): Response
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
        } catch (MethodNotAllowedException $e) {
            $response = $this->error(405, 'Methode nicht erlaubt.');
            $response->headers->set('Allow', implode(', ', $e->getAllowedMethods()));
        } catch (ValidationFailed $e) {
            $response = JsonReply::validation($e);
        } catch (AccessDenied $e) {
            $response = $this->error(403, $e->getMessage());
        } catch (PasswordChangeRequired) {
            $response = JsonReply::json(['error' => PasswordChangeRequired::MESSAGE, 'password_change_required' => true], 403);
        } catch (TooManyAttempts $e) {
            // Passwort-Bestätigung/-wechsel gesperrt (gleiche Sperre wie die Anmeldung): nichts wurde geprüft.
            $response = $this->error(429, $e->getMessage());
            $response->headers->set('Retry-After', (string) $e->retryAfter);
        } catch (LastAdminRemoved) {
            // Die Transaktion ist schon zurückgerollt (AdminInvariant prüft nach dem Schreiben, ADR 0005 E3).
            $response = $this->error(409, LastAdminRemoved::MESSAGE);
        } catch (\Throwable $e) {
            // Nie die Meldung, weder im Log noch in der Antwort (auch nicht in dev: der Masker hier kennt die
            // Geheimnisse der Jobs nicht). In dev höchstens Klasse und Datei:Zeile wie im Log.
            ErrorLog::unexpected($e, 'Kernel');
            $response = $this->error(
                500,
                $this->config->isDev() ? $this->masker->mask('Interner Fehler: ' . ErrorLog::where($e) . '.') : 'Interner Fehler.',
            );
        }

        return SecurityHeaders::apply($response, !$this->config->isDev() && $request->isSecure());
    }

    private function error(int $status, string $message): JsonResponse
    {
        return JsonReply::error($status, $message);
    }
}
