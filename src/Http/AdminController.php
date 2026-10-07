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

    public function auditList(Request $request): Response
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

    public function unlock(Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return self::error(401, 'Nicht angemeldet.');
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageUsers);
        if (!$this->csrf->check($request, $session->token)) {
            return self::error(403, 'CSRF-Prüfung fehlgeschlagen.');
        }

        $username = $this->username($request);
        if ($username === null) {
            return self::error(400, 'Ungültige Anfrage: JSON mit username erwartet (2 bis 64 Zeichen: Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich).');
        }

        $this->throttle->unlockUser($username);
        $this->audit->record($session->userId, 'auth.unlocked', $username);

        return self::json(['status' => 'ok']);
    }

    private function username(Request $request): ?string
    {
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY) {
            return null;
        }

        try {
            $data = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || !isset($data['username']) || !is_string($data['username']) || preg_match('/^[a-z0-9._-]{2,64}$/i', $data['username']) !== 1) {
            return null;
        }

        return $data['username'];
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
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private static function error(int $status, string $message): JsonResponse
    {
        return self::json(['error' => $message], $status);
    }
}
