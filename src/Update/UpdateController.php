<?php
// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);
namespace Meridian\Update;

use Meridian\Auth\{AuditLog, CsrfGuard};
use Meridian\Http\{SessionAuth, Kernel, JsonReply};
use Meridian\Security\{AccessControl, Permission};
use Meridian\User\UserRepository;
use Symfony\Component\HttpFoundation\{Request, Response};

/** The web process can request fixed actions; it never executes deployment commands. */
final class UpdateController
{
    public function __construct(private readonly SessionAuth $auth, private readonly CsrfGuard $csrf, private readonly UserRepository $users, private readonly AccessControl $access, private readonly AuditLog $audit, private readonly string $directory) {}

    public function register(Kernel $kernel): void
    {
        $kernel->get('update_status', '/api/admin/updates', $this->status(...));
        $kernel->post('update_check', '/api/admin/updates/check', fn (Request $request): Response => $this->action($request, 'check'));
        $kernel->post('update_install', '/api/admin/updates/install', fn (Request $request): Response => $this->action($request, 'install'));
        $kernel->post('update_channel_main', '/api/admin/updates/channel/main', fn (Request $request): Response => $this->action($request, 'channel_main'));
        $kernel->post('update_channel_beta', '/api/admin/updates/channel/beta', fn (Request $request): Response => $this->action($request, 'channel_beta'));
    }

    private function guard(#[\SensitiveParameter] Request $request, bool $mutating): int|Response
    {
        $session = $this->auth->authenticate($request);
        if ($session === null) { return JsonReply::error(401, 'Nicht angemeldet.'); }
        if ($mutating && !$this->csrf->check($request, $session->token)) { return JsonReply::csrfFailed(); }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageSettings);
        return $session->userId;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $path = $this->directory . '/status.json';
        if (!is_file($path) || is_link($path) || filesize($path) > 8192) { return ['configured' => false]; }
        $content = file_get_contents($path);
        $data = $content === false ? null : json_decode($content, true);
        if (!is_array($data)) { return ['configured' => false]; }
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

    private function action(#[\SensitiveParameter] Request $request, string $action): Response
    {
        $user = $this->guard($request, true);
        if ($user instanceof Response) { return $user; }
        if (trim($request->getContent()) !== '' && trim($request->getContent()) !== '{}') { return JsonReply::error(422, 'Diese Aktion akzeptiert keine Versionsnummern, Adressen oder Befehle.'); }
        $status = $this->read();
        if (($status['configured'] ?? false) !== true) { return JsonReply::error(409, 'Der Update-Dienst ist nicht eingerichtet oder nicht erreichbar.'); }
        if (($status['busy'] ?? false) === true) { return JsonReply::error(409, 'Ein Update-Vorgang läuft bereits.'); }
        if (str_starts_with($action, 'channel_') && !in_array($status['channel'] ?? null, ['main', 'beta'], true)) { return JsonReply::error(409, 'Zuerst den Update-Dienst auf die aktuelle Version bringen.'); }
        if ($action === 'install' && (($status['available'] ?? false) !== true || ($status['phase'] ?? '') === 'failed')) { return JsonReply::error(409, 'Zuerst nach einem verfügbaren Update suchen.'); }
        $id = bin2hex(random_bytes(16));
        $temp = $this->directory . '/request-' . $id . '.tmp';
        $payload = json_encode(['action' => $action, 'id' => $id], JSON_THROW_ON_ERROR);
        if (file_put_contents($temp, $payload, LOCK_EX) !== strlen($payload)) { return JsonReply::error(500, 'Update-Anfrage konnte nicht gespeichert werden.'); }
        try {
            if (!@link($temp, $this->directory . '/request.json')) { return JsonReply::error(409, 'Eine Update-Anfrage wartet bereits.'); }
        } finally { unlink($temp); }
        $this->audit->record($user, 'update.' . $action . '_requested', str_starts_with($action, 'channel_') ? substr($action, 8) : (in_array($status['channel'] ?? null, ['main', 'beta'], true) ? $status['channel'] : 'main'));
        return JsonReply::json(['queued' => true], 202);
    }
}
