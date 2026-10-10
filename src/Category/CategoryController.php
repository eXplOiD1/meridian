<?php

declare(strict_types=1);

namespace Meridian\Category;

use Meridian\Auth\CsrfGuard;
use Meridian\Http\JsonBody;
use Meridian\Http\JsonReply;
use Meridian\Http\Kernel;
use Meridian\Http\SessionAuth;
use Meridian\Http\ValidationFailed;
use Meridian\Job\CategoryName;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\Security\SecretMasker;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kategorien-Verwaltung (ADR 0005 E9, §4.2):
 *
 *   GET    /api/categories/manage                Liste mit Folgen je Kategorie
 *   GET    /api/categories/manage/{id}/impact    Folgen-Vorschau einer Kategorie
 *   POST   /api/categories/manage                {name}  → 201
 *   PUT    /api/categories/manage/{id}           {name}  → 200
 *   DELETE /api/categories/manage/{id}                   → 204, 409 bei Jobs
 *
 * Alle nur mit `categories.manage` (gefährlich: nur uneingeschränkt, nie für eine auf Kategorien beschränkte Rolle).
 * Ablauf: Sitzung (401) → bei ändernden Methoden CSRF und Herkunft (403) → Recht (403) → Eingabe (422) → Aktion
 * (404/409/422/429) → Audit `category.created`/`renamed`/`deleted`. Die Kategorie kommt aus der Datenbank; ein Bereich
 * (Scope) gilt hier nicht, weil das Recht nur uneingeschränkt vergeben wird. Texte laufen durch den Masker.
 * Die Liste für Formulare (`GET /api/categories`) bleibt davon unberührt und ist auf den Bereich des Benutzers gekappt.
 */
final class CategoryController
{
    private const MAX_BODY = 1024;
    private const ID = '[1-9][0-9]{0,17}';

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly CategoryService $service,
        private readonly SecretMasker $masker,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('categories_manage_list', '/api/categories/manage', $this->list(...));
        $kernel->get('categories_manage_impact', '/api/categories/manage/{id}/impact', $this->impact(...), ['id' => self::ID]);
        $kernel->post('categories_manage_create', '/api/categories/manage', $this->create(...));
        $kernel->put('categories_manage_rename', '/api/categories/manage/{id}', $this->rename(...), ['id' => self::ID]);
        $kernel->delete('categories_manage_delete', '/api/categories/manage/{id}', $this->delete(...), ['id' => self::ID]);
    }

    public function list(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->authorize($request, false);
        if ($session instanceof Response) {
            return $session;
        }

        return JsonReply::json(['categories' => array_map($this->present(...), $this->service->overview())]);
    }

    public function impact(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->authorize($request, false);
        if ($session instanceof Response) {
            return $session;
        }
        $impact = $this->service->impact((int) $request->attributes->getString('id'));
        if ($impact === null) {
            return JsonReply::error(404, 'Kategorie nicht gefunden.');
        }

        return JsonReply::json(['category' => $this->present($impact)]);
    }

    public function create(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->authorize($request, true);
        if ($session instanceof Response) {
            return $session;
        }
        $name = self::name($request);
        try {
            $id = $this->service->create($name, $session);
        } catch (CategoryException $e) {
            return $this->refuse($e);
        }

        return JsonReply::json(['category' => $this->present($this->service->impact($id) ?? throw new \LogicException('Kategorie fehlt nach dem Anlegen.'))], 201);
    }

    public function rename(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->authorize($request, true);
        if ($session instanceof Response) {
            return $session;
        }
        $id = (int) $request->attributes->getString('id');
        $name = self::name($request);
        try {
            $this->service->rename($id, $name, $session);
        } catch (CategoryException $e) {
            return $this->refuse($e);
        }

        return JsonReply::json(['category' => $this->present($this->service->impact($id) ?? throw new \LogicException('Kategorie fehlt nach dem Umbenennen.'))]);
    }

    public function delete(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->authorize($request, true);
        if ($session instanceof Response) {
            return $session;
        }
        try {
            $this->service->delete((int) $request->attributes->getString('id'), $session);
        } catch (CategoryException $e) {
            return $this->refuse($e);
        }

        return new Response(null, 204, ['Cache-Control' => 'no-store']);
    }

    /**
     * Sitzung → CSRF → Recht. Gibt bei Erfolg die Benutzer-ID zurück, sonst die fertige Fehlerantwort.
     */
    private function authorize(#[\SensitiveParameter] Request $request, bool $changes): int|Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if ($changes && !$this->csrf->check($request, $session->token)) {
            return JsonReply::csrfFailed();
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageCategories);

        return $session->userId;
    }

    /**
     * Genau {"name": "…"}: strenger Typ, keine unbekannten Felder, nichts wird zurechtgeschnitten.
     *
     * @throws ValidationFailed
     */
    private static function name(#[\SensitiveParameter] Request $request): string
    {
        $data = JsonBody::object($request->getContent(), self::MAX_BODY, 2);
        if ($data === null || array_diff(array_keys($data), ['name']) !== []) {
            throw ValidationFailed::field('body', 'JSON-Objekt mit genau dem Feld name erwartet (höchstens 1 KiB).');
        }
        $name = $data['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw ValidationFailed::field('name', 'Name fehlt (Text).');
        }
        if (!CategoryName::isValid($name)) {
            throw ValidationFailed::field('name', CategoryException::invalidName()->getMessage());
        }

        return $name;
    }

    private function refuse(CategoryException $e): Response
    {
        if ($e->status === CategoryException::INVALID) {
            throw ValidationFailed::field('name', $e->getMessage());
        }
        if ($e->status === CategoryException::CONFLICT) {
            return JsonReply::json(['error' => $e->getMessage(), 'jobs' => $e->jobs], 409);
        }
        $response = JsonReply::error($e->status, $e->getMessage());
        if ($e->status === CategoryException::TOO_MANY) {
            $response->headers->set('Retry-After', '3600');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CategoryImpact $impact): array
    {
        return [
            'id' => $impact->id,
            'name' => $this->masker->mask($impact->name),
            'jobs' => $impact->jobs,
            'assignments' => $impact->assignments,
            'assignments_ineffective' => $impact->assignmentsIneffective,
            'internal_targets' => $impact->internalTargets,
            'deletable' => $impact->deletable(),
        ];
    }
}
