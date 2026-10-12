<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\CsrfGuard;
use Meridian\Security\AccessControl;
use Meridian\Security\AccessDenied;
use Meridian\Security\Permission;
use Meridian\Security\SecretMasker;
use Meridian\Status\StatusItem;
use Meridian\Status\StatusPageRecord;
use Meridian\Status\StatusPageService;
use Meridian\Status\StatusPageRepository;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verwaltung der Statusseiten (ADR 0012): `GET/POST /api/status-pages`, `PUT/DELETE /api/status-pages/{id}`,
 * `POST /api/status-pages/{id}/link`. Alles mit `status_pages.edit` (leerer Bereich → 403), auf die erlaubten Kategorien
 * gekappt; nicht sichtbar → 404. Der Slug (der geheime Teil des öffentlichen Links) steht nur hier, für die Bearbeiter.
 * Interne Namen der Einträge sind maskiert; `no-store`.
 */
final class StatusPageController
{
    private const MAX_BODY = 32768;

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly StatusPageRepository $pages,
        private readonly StatusPageService $service,
        private readonly SecretMasker $masker,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $id = ['id' => '[1-9][0-9]{0,17}'];
        $kernel->get('status_pages_list', '/api/status-pages', $this->list(...));
        $kernel->post('status_pages_create', '/api/status-pages', $this->create(...));
        $kernel->put('status_pages_update', '/api/status-pages/{id}', $this->update(...), $id);
        $kernel->delete('status_pages_delete', '/api/status-pages/{id}', $this->delete(...), $id);
        $kernel->post('status_pages_link', '/api/status-pages/{id}/link', $this->renewLink(...), $id);
    }

    public function list(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request, false);
        if ($auth instanceof Response) {
            return $auth;
        }
        [, $grants] = $auth;
        $scope = $this->access->scope($grants, Permission::EditStatusPages);
        if ($scope->isEmpty()) {
            throw new AccessDenied(Permission::EditStatusPages);
        }

        return JsonReply::json(['pages' => array_map($this->present(...), $this->pages->list($scope)), 'max_items' => \Meridian\Status\StatusPageValidator::MAX_ITEMS]);
    }

    public function create(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request, true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;

        return JsonReply::json(['page' => $this->present($this->service->create($session->userId, $grants, self::body($request)))], 201);
    }

    public function update(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request, true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;
        $page = $this->service->update($session->userId, $grants, self::id($request), self::body($request));

        return $page === null ? JsonReply::error(404, 'Statusseite nicht gefunden.') : JsonReply::json(['page' => $this->present($page)]);
    }

    public function delete(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request, true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;

        return $this->service->delete($session->userId, $grants, self::id($request)) ? JsonReply::noContent() : JsonReply::error(404, 'Statusseite nicht gefunden.');
    }

    public function renewLink(#[\SensitiveParameter] Request $request): Response
    {
        $auth = $this->authenticate($request, true);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$session, $grants] = $auth;
        $page = $this->service->rotateSlug($session->userId, $grants, self::id($request));

        return $page === null ? JsonReply::error(404, 'Statusseite nicht gefunden.') : JsonReply::json(['page' => $this->present($page)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StatusPageRecord $page): array
    {
        return [
            'id' => $page->id,
            'title' => $this->masker->mask($page->title),
            'slug' => $page->slug,
            'path' => '/status/' . $page->slug,
            'category_id' => $page->categoryId,
            'category' => $page->categoryName === null ? null : $this->masker->mask($page->categoryName),
            'enabled' => $page->isEnabled,
            'items' => array_map(fn (StatusItem $i): array => [
                'type' => $i->type,
                'id' => $i->refId,
                'label' => $this->masker->mask($i->label),
                'name' => $i->refName === null ? null : $this->masker->mask($i->refName),
            ], $page->items),
            'created_at' => $page->createdAt,
        ];
    }

    /**
     * @return array{\Meridian\Auth\Session, list<\Meridian\Security\RoleGrant>}|JsonResponse
     */
    private function authenticate(#[\SensitiveParameter] Request $request, bool $mutating): array|JsonResponse
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if ($mutating && !$this->csrf->check($request, $session->token)) {
            return JsonReply::csrfFailed();
        }

        return [$session, $this->users->grantsFor($session->userId)];
    }

    /**
     * @return array<mixed>
     */
    private static function body(#[\SensitiveParameter] Request $request): array
    {
        $data = JsonBody::object($request->getContent(), self::MAX_BODY, 4);
        if ($data === null) {
            throw ValidationFailed::field('body', 'Der Anfragekörper muss ein JSON-Objekt sein (höchstens 32 KiB).');
        }
        if ($data !== [] && array_is_list($data)) {
            throw ValidationFailed::field('body', 'Der Anfragekörper muss ein JSON-Objekt sein (höchstens 32 KiB).');
        }

        return $data;
    }

    private static function id(#[\SensitiveParameter] Request $request): int
    {
        return (int) $request->attributes->getString('id');
    }
}
