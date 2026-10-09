<?php

declare(strict_types=1);

namespace Meridian\Network;

use Meridian\Auth\AuditLog;
use Meridian\Auth\CsrfGuard;
use Meridian\Http\JsonBody;
use Meridian\Http\JsonReply;
use Meridian\Http\Kernel;
use Meridian\Http\SessionAuth;
use Meridian\Http\ValidationFailed;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\Security\SecretMasker;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Freigaben interner Ziele für HTTP-Jobs (docs/decisions/0003, E5, §4.2):
 *
 *   GET    /api/settings/internal-targets
 *   POST   /api/settings/internal-targets          {kind, value, port, category_id, note}
 *   DELETE /api/settings/internal-targets/{id}
 *
 * Alle drei nur mit `network.internal_targets` (gefährlich: nur uneingeschränkt, nie für eine auf Kategorien
 * beschränkte Rolle). Ablauf: Sitzung (401) → bei POST/DELETE CSRF und Herkunft (403) → Recht (403) → Eingabe
 * (422) → Aktion → Audit `network.internal_target_added`/`_removed`. Texte der Antwort laufen durch den Masker.
 */
final class InternalTargetController
{
    private const MAX_BODY = 2048;

    private const FIELDS = ['category_id', 'kind', 'note', 'port', 'value'];

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly AuditLog $audit,
        private readonly InternalTargetStore $store,
        private readonly SecretMasker $masker,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('internal_targets_list', '/api/settings/internal-targets', $this->list(...));
        $kernel->post('internal_targets_add', '/api/settings/internal-targets', $this->add(...));
        $kernel->delete('internal_targets_remove', '/api/settings/internal-targets/{id}', $this->remove(...), ['id' => '[1-9][0-9]{0,17}']);
    }

    public function list(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageInternalTargets);

        return JsonReply::json(['targets' => array_map($this->present(...), $this->store->all())]);
    }

    public function add(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if (!$this->csrf->check($request, $session->token)) {
            return JsonReply::error(403, 'CSRF-Prüfung fehlgeschlagen.');
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageInternalTargets);

        [$kind, $value, $port, $categoryId, $note] = self::input($request);
        try {
            $target = $this->store->validate($kind, $value, $port, $categoryId, $note);
            // Freigabe und Audit in einer Transaktion (scheitert das Protokoll, wird zurückgerollt).
            $record = $this->store->add($target, $note, $session->userId, $this->audit);
        } catch (InvalidTargetInput $e) {
            throw ValidationFailed::field($e->field, $e->getMessage());
        }

        return JsonReply::json(['target' => $this->present($record)], 201);
    }

    public function remove(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if (!$this->csrf->check($request, $session->token)) {
            return JsonReply::error(403, 'CSRF-Prüfung fehlgeschlagen.');
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageInternalTargets);

        $id = $request->attributes->getString('id');
        $record = preg_match('/^[1-9][0-9]{0,17}$/D', $id) === 1 ? $this->store->remove((int) $id, $session->userId, $this->audit) : null;
        if ($record === null) {
            return JsonReply::error(404, 'Freigabe nicht gefunden.');
        }

        return new Response(null, 204, ['Cache-Control' => 'no-store']);
    }

    /**
     * Strenge Typen, nur bekannte Felder; nichts wird umgedeutet („8080“ ist kein Port).
     *
     * @return array{0: string, 1: string, 2: int, 3: int|null, 4: string}
     *
     * @throws ValidationFailed
     */
    private static function input(#[\SensitiveParameter] Request $request): array
    {
        $data = JsonBody::object($request->getContent(), self::MAX_BODY, 2);
        if ($data === null) {
            throw ValidationFailed::field('body', 'JSON-Objekt mit kind, value, port, category_id und note erwartet (höchstens 2 KiB).');
        }
        $errors = [];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::FIELDS, true)) {
                $errors['body'] = 'Unbekanntes Feld. Erlaubt sind kind, value, port, category_id und note.';
            }
        }
        $kind = $data['kind'] ?? null;
        if ($kind !== 'cidr' && $kind !== 'host') {
            $errors['kind'] = 'Art fehlt oder ist unbekannt. Erlaubt sind „cidr“ (Netz) und „host“ (Hostname).';
        }
        $value = $data['value'] ?? null;
        if (!is_string($value) || $value === '') {
            $errors['value'] = 'Netz oder Hostname fehlt (Text).';
        }
        $port = $data['port'] ?? 0;
        if (!is_int($port) || $port < 0 || $port > 65535) {
            $errors['port'] = 'Port muss eine ganze Zahl von 1 bis 65535 sein, 0 für alle Ports.';
        }
        $category = $data['category_id'] ?? null;
        if ($category !== null && (!is_int($category) || $category < 1)) {
            $errors['category_id'] = 'Kategorie als Zahl (ID) angeben oder null für global.';
        }
        $note = $data['note'] ?? '';
        if (!is_string($note)) {
            $errors['note'] = 'Die Notiz muss Text sein.';
        }
        if ($errors !== [] || !is_string($kind) || !is_string($value) || !is_int($port) || ($category !== null && !is_int($category)) || !is_string($note)) {
            throw new ValidationFailed($errors);
        }

        return [$kind, $value, $port, $category, $note];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(InternalTargetRecord $record): array
    {
        return [
            'id' => $record->id,
            'kind' => $record->kind,
            'value' => $this->masker->mask($record->value),
            'port' => $record->port,
            'category' => $record->categoryId === null ? null : ['id' => $record->categoryId, 'name' => $this->masker->mask($record->categoryName ?? '')],
            'note' => $this->masker->mask($record->note),
            'created_at' => $record->createdAt,
            'created_by' => $record->createdBy === null ? null : ['display_name' => $this->masker->mask($record->createdBy)],
        ];
    }
}
