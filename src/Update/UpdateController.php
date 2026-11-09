<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);

namespace Meridian\Update;

use Meridian\Auth\{AuditLog, AuthService, CsrfGuard};
use Meridian\Config;
use Meridian\Http\{ClientIp, JsonBody, SessionAuth, Kernel, JsonReply, ValidationFailed};
use Meridian\Security\{AccessControl, Permission};
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\{Request, Response};

/** The web process can request fixed actions; it never executes deployment commands. */
final class UpdateController
{
    /** Fields of the update source; the daemon validates them again before it saves anything. */
    private const SOURCE_FIELDS = ['kind', 'repo', 'gitlab_url', 'registry', 'username', 'token', 'clear_token', 'current_password'];

    public function __construct(private readonly SessionAuth $auth, private readonly CsrfGuard $csrf, private readonly UserRepository $users, private readonly AccessControl $access, private readonly AuditLog $audit, private readonly string $directory, private readonly ?AuthService $authService = null, private readonly ?Config $config = null)
    {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('update_status', '/api/admin/updates', $this->status(...));
        $kernel->post('update_check', '/api/admin/updates/check', fn (Request $request): Response => $this->action($request, 'check'));
        $kernel->post('update_install', '/api/admin/updates/install', fn (Request $request): Response => $this->action($request, 'install'));
        $kernel->post('update_channel_main', '/api/admin/updates/channel/main', fn (Request $request): Response => $this->action($request, 'channel_main'));
        $kernel->post('update_channel_beta', '/api/admin/updates/channel/beta', fn (Request $request): Response => $this->action($request, 'channel_beta'));
        $kernel->put('update_source', '/api/admin/updates/source', $this->source(...));
    }

    private function guard(#[\SensitiveParameter] Request $request, bool $mutating): int|Response
    {
        $session = $this->auth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if ($mutating && !$this->csrf->check($request, $session->token)) {
            return JsonReply::csrfFailed();
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageSettings);
        return $session->userId;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $path = $this->directory . '/status.json';
        if (!is_file($path) || is_link($path) || filesize($path) > 8192) {
            return ['configured' => false];
        }
        $content = file_get_contents($path);
        $data = $content === false ? null : json_decode($content, true);
        if (!is_array($data)) {
            return ['configured' => false];
        }
        /** @var array<string, mixed> $data */
        $data['configured'] = filemtime($path) >= time() - 90;
        $data['pending'] = is_file($this->directory . '/request.json');
        return $data;
    }

    public function status(#[\SensitiveParameter] Request $request): Response
    {
        $user = $this->guard($request, false);
        return $user instanceof Response ? $user : JsonReply::json($this->read());
    }

    /**
     * Changing the update source decides where the privileged updater installs code from, so it needs the own current
     * password (same lockout as login) and is audited. The token is write-only: it reaches the daemon once, through a
     * 0600 request file that the daemon deletes while reading, and never appears in a response, status, audit entry or log.
     */
    public function source(#[\SensitiveParameter] Request $request): Response
    {
        $user = $this->guard($request, true);
        if ($user instanceof Response) {
            return $user;
        }
        $data = JsonBody::object($request->getContent(), 4096);
        if ($data === null || array_diff(array_keys($data), self::SOURCE_FIELDS) !== []) {
            throw ValidationFailed::field('body', 'Erlaubt sind kind, repo, gitlab_url, registry, username, token, clear_token und current_password.');
        }
        $source = $this->validSource($data);
        $password = $data['current_password'] ?? null;
        if (!is_string($password) || $password === '' || strlen($password) > AuthService::MAX_PASSWORD) {
            throw ValidationFailed::field('current_password', 'Zum Ändern der Update-Quelle das eigene aktuelle Passwort bestätigen.');
        }
        if ($this->authService === null || $this->config === null) {
            return JsonReply::error(500, 'Die Passwortbestätigung ist nicht verfügbar.');
        }
        $account = $this->users->findById($user);
        if ($account === null || !$account->isActive) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        $ip = ClientIp::forThrottle($request, $this->config);
        if ($ip instanceof Response) {
            return $ip;
        }
        if (!$this->authService->confirmPassword($account, $password, $ip)) {
            return JsonReply::error(403, 'Das aktuelle Passwort ist falsch.');
        }
        $status = $this->read();
        if (($status['configured'] ?? false) !== true) {
            return JsonReply::error(409, 'Der Update-Dienst ist nicht eingerichtet oder nicht erreichbar.');
        }
        if (($status['busy'] ?? false) === true) {
            return JsonReply::error(409, 'Ein Update-Vorgang läuft bereits.');
        }
        if (!isset($status['source']) || !is_array($status['source'])) {
            return JsonReply::error(409, 'Zuerst den Update-Dienst auf die aktuelle Version bringen.');
        }
        // Der Token darf nie für Gruppe oder andere lesbar sein: Datei zuerst privat anlegen, dann schreiben.
        $failure = $this->queue('source', ['source' => $source], true);
        if ($failure !== null) {
            return $failure;
        }
        $this->audit->record($user, 'update.source_requested', $source['kind'] . ' ' . $source['repo'] . (isset($source['token']) ? ' (neues Token)' : '') . (isset($source['clear_token']) ? ' (Token entfernt)' : ''));
        return JsonReply::json(['queued' => true], 202);
    }

    /**
     * Whitelist per field, mirrored by the daemon. Rejected, never trimmed or repaired.
     *
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private function validSource(#[\SensitiveParameter] array $data): array
    {
        $text = static function (string $field) use ($data): string {
            $value = $data[$field] ?? '';
            if (!is_string($value)) {
                throw ValidationFailed::field($field, 'Dieses Feld muss ein Text sein.');
            }
            return $value;
        };
        $kind = $text('kind');
        if (!in_array($kind, ['github', 'gitlab'], true)) {
            throw ValidationFailed::field('kind', 'Die Quelle muss github oder gitlab sein.');
        }
        $repo = $text('repo');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*(\/[A-Za-z0-9][A-Za-z0-9_.-]*){1,4}$/D', $repo) !== 1) {
            throw ValidationFailed::field('repo', 'Das Repository muss die Form besitzer/name haben (bei GitLab auch gruppe/untergruppe/name).');
        }
        $gitlabUrl = $text('gitlab_url');
        if ($gitlabUrl !== '' && ($kind !== 'gitlab' || preg_match('/^https:\/\/[a-z0-9][a-z0-9.-]*(:[0-9]{1,5})?$/D', $gitlabUrl) !== 1)) {
            throw ValidationFailed::field('gitlab_url', 'Die GitLab-Adresse muss mit https:// beginnen und darf keinen Pfad enthalten; sie gilt nur für GitLab.');
        }
        $registry = $text('registry');
        if ($registry !== '' && preg_match('/^[a-z0-9][a-z0-9.-]*(:[0-9]{1,5})?(\/[a-z0-9][a-z0-9_.-]*)+$/D', $registry) !== 1) {
            throw ValidationFailed::field('registry', 'Die Registry muss klein geschrieben sein und die Form host/pfad haben, z. B. ghcr.io/besitzer.');
        }
        if ($kind === 'gitlab' && $gitlabUrl !== '' && $gitlabUrl !== 'https://gitlab.com' && $registry === '') {
            throw ValidationFailed::field('registry', 'Bei selbst gehostetem GitLab die Registry angeben.');
        }
        $username = $text('username');
        if ($username !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.@-]{0,99}$/D', $username) !== 1) {
            throw ValidationFailed::field('username', 'Der Benutzername enthält ungültige Zeichen.');
        }
        $token = $text('token');
        if ($token !== '' && preg_match('/^[A-Za-z0-9_.-]{20,255}$/D', $token) !== 1) {
            throw ValidationFailed::field('token', 'Das Token hat ein ungültiges Format (nur Buchstaben, Ziffern, _ . -; 20 bis 255 Zeichen).');
        }
        $clear = $data['clear_token'] ?? false;
        if (!is_bool($clear) || ($clear && $token !== '')) {
            throw ValidationFailed::field('clear_token', 'Ein neues Token und „Token entfernen“ schließen sich aus.');
        }
        $source = ['kind' => $kind, 'repo' => $repo, 'gitlab_url' => $gitlabUrl, 'registry' => $registry, 'username' => $username];
        if ($token !== '') {
            $source['token'] = $token;
        }
        if ($clear) {
            $source['clear_token'] = true;
        }
        return $source;
    }

    private function action(#[\SensitiveParameter] Request $request, string $action): Response
    {
        $user = $this->guard($request, true);
        if ($user instanceof Response) {
            return $user;
        }
        if (trim($request->getContent()) !== '' && trim($request->getContent()) !== '{}') {
            return JsonReply::error(422, 'Diese Aktion akzeptiert keine Versionsnummern, Adressen oder Befehle.');
        }
        $status = $this->read();
        if (($status['configured'] ?? false) !== true) {
            return JsonReply::error(409, 'Der Update-Dienst ist nicht eingerichtet oder nicht erreichbar.');
        }
        if (($status['busy'] ?? false) === true) {
            return JsonReply::error(409, 'Ein Update-Vorgang läuft bereits.');
        }
        if (str_starts_with($action, 'channel_') && !in_array($status['channel'] ?? null, ['main', 'beta'], true)) {
            return JsonReply::error(409, 'Zuerst den Update-Dienst auf die aktuelle Version bringen.');
        }
        if ($action === 'install' && (($status['available'] ?? false) !== true || ($status['phase'] ?? '') === 'failed')) {
            return JsonReply::error(409, 'Zuerst nach einem verfügbaren Update suchen.');
        }
        $failure = $this->queue($action, [], false);
        if ($failure !== null) {
            return $failure;
        }
        $this->audit->record($user, 'update.' . $action . '_requested', str_starts_with($action, 'channel_') ? substr($action, 8) : (in_array($status['channel'] ?? null, ['main', 'beta'], true) ? $status['channel'] : 'main'));
        return JsonReply::json(['queued' => true], 202);
    }

    /**
     * Legt die Anfrage für den Update-Dienst als `request.json` ab (erst eine eigene Datei, dann ein harter Link: eine
     * wartende Anfrage wird nie überschrieben). Gibt bei Erfolg null zurück, sonst die Fehlerantwort.
     *
     * @param array<string, mixed> $extra weitere Felder der Anfrage
     * @param bool                 $private Datei mit 0600 anlegen (die Anfrage enthält einen Token)
     */
    private function queue(string $action, #[\SensitiveParameter] array $extra, bool $private): ?Response
    {
        $id = bin2hex(random_bytes(16));
        $temp = $this->directory . '/request-' . $id . '.tmp';
        $payload = json_encode(['action' => $action, 'id' => $id] + $extra, JSON_THROW_ON_ERROR);
        $written = (!$private || (@touch($temp) !== false && @chmod($temp, 0600) !== false))
            && file_put_contents($temp, $payload, LOCK_EX) === strlen($payload);
        if (!$written) {
            @unlink($temp);

            return JsonReply::error(500, 'Update-Anfrage konnte nicht gespeichert werden.');
        }
        try {
            if (!@link($temp, $this->directory . '/request.json')) {
                return JsonReply::error(409, 'Eine Update-Anfrage wartet bereits.');
            }
        } finally {
            unlink($temp);
        }

        return null;
    }
}
