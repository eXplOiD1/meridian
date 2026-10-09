<?php

declare(strict_types=1);

namespace Meridian\Settings;

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
 * Globale Einstellungen (docs/decisions/0003, E10, §4.2, §4.3):
 *
 *   GET /api/settings          → {"settings": [{key, value, default, source, updated_at, updated_by}, …]}
 *   PUT /api/settings/{key}    {"value": …} → {"setting": {…}, "tightened_jobs": N}; {"value": null} = Standard
 *
 * Beide nur mit `settings.manage` (gefährlich: nur uneingeschränkt). Ablauf: Sitzung (401) → bei PUT CSRF und
 * Herkunft (403) → Recht (403) → Schlüssel aus der Allowlist (404) → Wert (422) → {@see SettingsService::change()}
 * (Schreiben, Verschärfen, Audit in einer Transaktion). Texte der Antwort laufen durch den Masker.
 */
final class SettingsController
{
    private const MAX_BODY = 1024;

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly SettingsService $service,
        private readonly SecretMasker $masker,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('settings_list', '/api/settings', $this->list(...));
        $kernel->put('settings_change', '/api/settings/{key}', $this->change(...), ['key' => '[a-z][a-z0-9_.]{0,63}']);
    }

    public function list(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageSettings);

        return JsonReply::json(['settings' => array_map($this->present(...), $this->service->entries())]);
    }

    public function change(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if (!$this->csrf->check($request, $session->token)) {
            return JsonReply::error(403, 'CSRF-Prüfung fehlgeschlagen.');
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageSettings);

        $key = SettingKey::tryFrom($request->attributes->getString('key'));
        if ($key === null) {
            return JsonReply::error(404, 'Einstellung unbekannt. Erlaubt sind ' . implode(', ', array_map(static fn (SettingKey $k): string => $k->value, SettingKey::cases())) . '.');
        }

        try {
            $change = $this->service->change($key, self::value($request), $session->userId);
        } catch (InvalidSetting $e) {
            throw ValidationFailed::field('value', $e->getMessage());
        }

        return JsonReply::json(['setting' => $this->present($change->entry), 'tightened_jobs' => $change->tightenedJobs]);
    }

    /**
     * Genau ein Feld `value`; Typ und Bereich prüft {@see Settings::validate()} (nichts wird umgedeutet).
     *
     * @throws ValidationFailed
     */
    private static function value(#[\SensitiveParameter] Request $request): mixed
    {
        $data = JsonBody::object($request->getContent(), self::MAX_BODY, 2);
        if ($data === null || array_keys($data) !== ['value']) {
            throw ValidationFailed::field('body', 'JSON-Objekt mit genau einem Feld „value“ erwartet (null setzt auf den Standard zurück).');
        }

        return $data['value'];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SettingEntry $entry): array
    {
        return [
            'key' => $entry->key->value,
            'value' => $this->scalar($entry->value),
            'default' => $this->scalar($entry->key->default()),
            'source' => $entry->source(),
            'updated_at' => $entry->updatedAt,
            'updated_by' => $entry->updatedBy === null ? null : ['display_name' => $this->masker->mask($entry->updatedBy)],
        ];
    }

    private function scalar(int|string $value): int|string
    {
        return is_string($value) ? $this->masker->mask($value) : $value;
    }
}
