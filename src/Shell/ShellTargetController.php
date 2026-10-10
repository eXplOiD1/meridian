<?php

declare(strict_types=1);

namespace Meridian\Shell;

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
 * Ausführungsorte für Shell-Jobs (docs/decisions/0004 E4, §4.1):
 *
 *   GET    /api/settings/shell-targets
 *   POST   /api/settings/shell-targets          {kind, name, category_id, users, default_user, note}
 *   DELETE /api/settings/shell-targets/{id}
 *   GET    /api/shell/targets?category_id=      (Auswahl im Job-Editor)
 *
 * Die drei ersten nur mit `shell.targets` (gefährlich: nur uneingeschränkt, nie für eine auf Kategorien beschränkte
 * Rolle), die Auswahl nur mit `jobs.edit_shell` (ebenso). Ablauf: Sitzung (401) → bei POST/DELETE CSRF und Herkunft
 * (403) → Recht (403) → Eingabe (422) → Aktion → Audit `shell.target_added`/`_removed`. Texte der Antwort laufen
 * durch den Masker.
 */
final class ShellTargetController
{
    private const MAX_BODY = 4096;

    private const FIELDS = ['category_id', 'default_user', 'kind', 'name', 'note', 'users'];

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly AuditLog $audit,
        private readonly ShellTargetStore $store,
        private readonly SecretMasker $masker,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('shell_targets_list', '/api/settings/shell-targets', $this->list(...));
        $kernel->post('shell_targets_add', '/api/settings/shell-targets', $this->add(...));
        $kernel->delete('shell_targets_remove', '/api/settings/shell-targets/{id}', $this->remove(...), ['id' => '[1-9][0-9]{0,17}']);
        $kernel->get('shell_targets_usable', '/api/shell/targets', $this->usable(...));
    }

    public function list(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageShellTargets);

        return JsonReply::json(['targets' => array_map($this->present(...), $this->store->all())]);
    }

    public function add(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if (!$this->csrf->check($request, $session->token)) {
            return JsonReply::csrfFailed();
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageShellTargets);

        [$kind, $name, $categoryId, $users, $default, $note] = self::input($request);
        try {
            $input = $this->store->validate($kind, $name, $categoryId, $users, $default, $note);
            $record = $this->store->add($input, $session->userId, $this->audit);
        } catch (InvalidShellTarget $e) {
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
            return JsonReply::csrfFailed();
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageShellTargets);

        $id = $request->attributes->getString('id');
        $record = preg_match('/^[1-9][0-9]{0,17}$/D', $id) === 1 ? $this->store->remove((int) $id, $session->userId, $this->audit) : null;
        if ($record === null) {
            return JsonReply::error(404, 'Ausführungsort nicht gefunden.');
        }

        return new Response(null, 204, ['Cache-Control' => 'no-store']);
    }

    /**
     * Auswahl für den Job-Editor: nur Orte, die für die Kategorie des Jobs nutzbar sind (global oder genau diese).
     * Ohne `category_id` (Job ohne Kategorie) nur globale. Gleiche Orte aus mehreren Freigaben werden vereint.
     */
    public function usable(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::EditShellJobs);

        $query = $request->query->all();
        $unknown = array_diff(array_map(strval(...), array_keys($query)), ['category_id']);
        if ($unknown !== []) {
            throw ValidationFailed::field('query', 'Unbekannter Parameter. Erlaubt ist nur category_id.');
        }
        $categoryId = null;
        if (array_key_exists('category_id', $query)) {
            $raw = $query['category_id'];
            if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,17}$/D', $raw) !== 1 || !$this->store->categoryExists((int) $raw)) {
                throw ValidationFailed::field('category_id', 'Kategorie unbekannt oder keine gültige Nummer.');
            }
            $categoryId = (int) $raw;
        }

        /** @var array<string, array{kind: string, name: string, users: list<string>, default: ?string, own_default: ?string, root: bool}> $merged */
        $merged = [];
        foreach ($this->store->usableIn($categoryId) as $record) {
            $key = $record->target->describe();
            $entry = $merged[$key] ?? ['kind' => $record->target->kind->value, 'name' => $record->target->name, 'users' => [], 'default' => null, 'own_default' => null, 'root' => false];
            $entry['users'] = array_values(array_unique([...$entry['users'], ...$record->users]));
            $entry['root'] = $entry['root'] || $record->allowsRoot();
            if ($record->categoryId !== null) {
                $entry['own_default'] = $record->defaultUser;
            } else {
                $entry['default'] = $record->defaultUser;
            }
            $merged[$key] = $entry;
        }
        $out = [];
        foreach ($merged as $entry) {
            $out[] = [
                'kind' => $entry['kind'],
                'name' => $this->masker->mask($entry['name']),
                'users' => $entry['users'],
                'default_user' => $entry['own_default'] ?? $entry['default'],
                'allows_root' => $entry['root'],
            ];
        }

        return JsonReply::json(['targets' => $out]);
    }

    /**
     * Strenge Typen, nur bekannte Felder; nichts wird umgedeutet.
     *
     * @return array{0: string, 1: string, 2: int|null, 3: array<mixed>, 4: string|null, 5: string}
     *
     * @throws ValidationFailed
     */
    private static function input(#[\SensitiveParameter] Request $request): array
    {
        $data = JsonBody::object($request->getContent(), self::MAX_BODY, 3);
        $message = 'JSON-Objekt mit kind, name, category_id, users, default_user und note erwartet (höchstens 4 KiB).';
        if ($data === null) {
            throw ValidationFailed::field('body', $message);
        }
        if ($data !== [] && array_is_list($data)) {
            throw ValidationFailed::field('body', $message);
        }
        $errors = [];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::FIELDS, true)) {
                $errors['body'] = 'Unbekanntes Feld. Erlaubt sind kind, name, category_id, users, default_user und note.';
            }
        }
        $kind = $data['kind'] ?? null;
        if (!is_string($kind)) {
            $errors['kind'] = 'Art fehlt oder ist unbekannt. Erlaubt sind „docker“ (Container) und „host“ (Host-Profil).';
        }
        $name = $data['name'] ?? null;
        if (!is_string($name)) {
            $errors['name'] = 'Name fehlt (Text).';
        }
        $category = $data['category_id'] ?? null;
        if ($category !== null && (!is_int($category) || $category < 1)) {
            $errors['category_id'] = 'Kategorie als Zahl (ID) angeben oder null für global.';
        }
        $users = $data['users'] ?? [];
        if (!is_array($users)) {
            $errors['users'] = 'Erlaubte Benutzer: Liste von Texten.';
        }
        $default = $data['default_user'] ?? null;
        if ($default !== null && !is_string($default)) {
            $errors['default_user'] = 'Der Standardbenutzer muss Text oder null sein.';
        }
        $note = $data['note'] ?? '';
        if (!is_string($note)) {
            $errors['note'] = 'Die Notiz muss Text sein.';
        }
        if ($errors !== [] || !is_string($kind) || !is_string($name) || ($category !== null && !is_int($category)) || !is_array($users)
            || ($default !== null && !is_string($default)) || !is_string($note)) {
            throw new ValidationFailed($errors);
        }

        return [$kind, $name, $category, $users, $default, $note];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ShellTargetRecord $record): array
    {
        return [
            'id' => $record->id,
            'kind' => $record->target->kind->value,
            'name' => $this->masker->mask($record->target->name),
            'category' => $record->categoryId === null ? null : ['id' => $record->categoryId, 'name' => $this->masker->mask($record->categoryName ?? '')],
            'users' => $record->users,
            'default_user' => $record->defaultUser,
            'allows_root' => $record->allowsRoot(),
            'note' => $this->masker->mask($record->note),
            'created_at' => $record->createdAt,
            'created_by' => $record->createdBy === null ? null : ['display_name' => $this->masker->mask($record->createdBy)],
        ];
    }
}
