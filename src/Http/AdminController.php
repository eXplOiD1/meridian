<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\AuditLog;
use Meridian\Auth\CsrfGuard;
use Meridian\Auth\LoginThrottle;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\Security\SecretMasker;
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verwaltungsfunktionen rund um Anmeldung: Audit-Log lesen und gesperrte Konten freigeben.
 * Beides erfordert das Recht users.manage (gefährlich, also nur uneingeschränkt vergebbar).
 */
final class AdminController
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    private const MAX_BODY = 1024;

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly AuditLog $audit,
        private readonly LoginThrottle $throttle,
        private readonly SecretMasker $masker,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('audit_list', '/api/audit', $this->auditList(...));
        $kernel->post('users_unlock', '/api/users/unlock', $this->unlock(...));
    }

    public function auditList(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return self::error(401, 'Nicht angemeldet.');
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageUsers);

        // all() statt get(): get() wirft bei Arrays wie ?limit[]=1 eine Exception (würde als 500 enden).
        $query = $request->query->all();
        $limit = self::intParameter($query, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $before = self::intParameter($query, 'before_id', null, 1, PHP_INT_MAX);
        $action = $query['action'] ?? null;
        if ($limit === false || $limit === null || $before === false || ($action !== null && (!is_string($action) || preg_match('/^[a-z0-9_.]{1,64}$/', $action) !== 1))) {
            return self::error(400, 'Ungültige Abfrage: limit 1 bis ' . self::MAX_LIMIT . ', before_id positive Zahl, action nur a-z, 0-9, Punkt, Unterstrich.');
        }

        $entries = $this->audit->recent($limit, $before, is_string($action) ? $action : null);
        $out = [];
        foreach ($entries as $entry) {
            // Alles, was das System verlässt, läuft durch den Masker (zusätzlich zum Maskieren beim Schreiben).
            $entry['target'] = $entry['target'] === null ? null : $this->masker->mask($entry['target']);
            $out[] = $entry;
        }

        return self::json([
            'entries' => $out,
            'next_before_id' => count($out) === $limit && $out !== [] ? $out[count($out) - 1]['id'] : null,
        ]);
    }

    public function unlock(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return self::error(401, 'Nicht angemeldet.');
        }
        // Reihenfolge wie überall (mer-security §1): CSRF/Herkunft vor dem Recht.
        if (!$this->csrf->check($request, $session->token)) {
            return self::error(403, 'CSRF-Prüfung fehlgeschlagen.');
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageUsers);

        $target = $this->unlockTarget($request);
        if ($target === null) {
            return self::error(400, 'Ungültige Anfrage: JSON mit username (2 bis 64 Zeichen: Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich) oder ip erwartet.');
        }

        if ($target['kind'] === 'ip') {
            $this->throttle->unlockIp($target['value']);
        } else {
            $this->throttle->unlockUser($target['value']);
        }
        $this->audit->record($session->userId, 'auth.unlocked', ($target['kind'] === 'ip' ? 'ip:' : '') . $target['value']);

        return self::json(['status' => 'ok']);
    }

    /**
     * @return array{kind: 'user'|'ip', value: string}|null genau eines von username oder ip, sonst null
     */
    private function unlockTarget(#[\SensitiveParameter] Request $request): ?array
    {
        $data = JsonBody::object($request->getContent(), self::MAX_BODY);
        if ($data === null || (isset($data['username']) === isset($data['ip']))) {
            return null;
        }

        if (isset($data['username'])) {
            return is_string($data['username']) && preg_match('/^[a-z0-9._-]{2,64}$/i', $data['username']) === 1
                ? ['kind' => 'user', 'value' => $data['username']]
                : null;
        }

        return is_string($data['ip']) && filter_var($data['ip'], FILTER_VALIDATE_IP) !== false
            ? ['kind' => 'ip', 'value' => $data['ip']]
            : null;
    }

    /**
     * @param array<mixed> $query
     *
     * @return int|false|null int bei gültigem Wert, null wenn nicht angegeben und kein Standard, false bei Fehler
     */
    private static function intParameter(array $query, string $name, ?int $default, int $min, int $max): int|false|null
    {
        $raw = $query[$name] ?? null;
        if ($raw === null) {
            return $default;
        }
        if (!is_string($raw) || preg_match('/^[0-9]{1,18}$/', $raw) !== 1) {
            return false;
        }

        $value = (int) $raw;

        return $value >= $min && $value <= $max ? $value : false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data, int $status = 200): JsonResponse
    {
        return JsonReply::json($data, $status);
    }

    private static function error(int $status, string $message): JsonResponse
    {
        return JsonReply::error($status, $message);
    }
}
