<?php
// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);
namespace Meridian\Notify;

use Meridian\Auth\{CsrfGuard, Clock, AuditLog};
use Meridian\Database\{Connection, Timestamp};
use Meridian\Http\{SessionAuth, Kernel, JsonBody, JsonReply};
use Meridian\Security\{AccessControl, Permission, SecretBox};
use Meridian\User\UserRepository;
use Meridian\Job\Row;
use Symfony\Component\HttpFoundation\{Request, Response};

final class BrowserPushController
{
    public function __construct(private readonly SessionAuth $auth, private readonly CsrfGuard $csrf, private readonly UserRepository $users, private readonly AccessControl $access, private readonly ChannelStore $channels, private readonly Connection $db, private readonly SecretBox $box, private readonly Clock $clock, private readonly AuditLog $audit) {}

    public function register(Kernel $kernel): void
    {
        $ids = ['id' => '[1-9][0-9]{0,17}', 'device' => '[1-9][0-9]{0,17}'];
        $kernel->get('browser_push_personal', '/api/account/browser-channels', $this->personal(...));
        $kernel->post('browser_push_personal_test', '/api/notifications/channels/{id}/browser/test', $this->test(...), $ids);
        $kernel->get('browser_push_devices', '/api/notifications/channels/{id}/browser', $this->devices(...), $ids);
        $kernel->post('browser_push_subscribe', '/api/notifications/channels/{id}/browser', $this->subscribe(...), $ids);
        $kernel->delete('browser_push_remove', '/api/notifications/channels/{id}/browser/{device}', $this->remove(...), $ids);
    }

    private function guard(#[\SensitiveParameter] Request $request, bool $mutating): int|Response
    {
        $session = $this->auth->authenticate($request);
        if ($session === null) { return JsonReply::error(401, 'Nicht angemeldet.'); }
        if ($mutating && !$this->csrf->check($request, $session->token)) { return JsonReply::csrfFailed(); }
        $channel = $this->channels->find((int) $request->attributes->getString('id'));
        if ($channel === null || $channel->type !== ChannelType::Browser || (!$channel->isEnabled && $mutating && $request->getMethod() !== 'DELETE')) { return JsonReply::error(404, 'Browser-Kanal nicht gefunden.'); }
        $own = in_array($request->getMethod(), ['GET', 'DELETE'], true) && $this->hasDevices($session->userId, $channel->id);
        if (!$own && !$this->eligible($session->userId, $channel->id)) { return JsonReply::error(403, 'Keine Berechtigung für diesen Browser-Kanal.'); }
        return $session->userId;
    }

    private function hasDevices(int $user, int $channel): bool
    {
        return $this->db->fetchOne('SELECT id FROM browser_push_subscriptions WHERE user_id = :user AND channel_id = :channel LIMIT 1', ['user' => $user, 'channel' => $channel]) !== null;
    }

    private function eligible(int $user, int $channel): bool
    {
        $grants = $this->users->grantsFor($user);
        if ($this->access->can($grants, Permission::ManageSettings)) { return true; }
        $scope = $this->access->scope($grants, Permission::ViewJobs);
        $params = $scope->sqlParameters() + ['channel' => $channel];
        return $this->db->fetchOne("SELECT j.id FROM jobs j LEFT JOIN categories c ON c.id = j.category_id WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names))) AND (j.notify_channels = 'all' OR (j.notify_channels = 'selected' AND EXISTS (SELECT 1 FROM job_channels jc WHERE jc.job_id = j.id AND jc.channel_id = :channel))) LIMIT 1", $params) !== null
            || $this->db->fetchOne("SELECT m.id FROM monitors m LEFT JOIN categories c ON c.id = m.category_id WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names))) AND m.notify_channels = 'all' AND (m.notify_channel_ids IS NULL OR EXISTS (SELECT 1 FROM monitor_channels mc WHERE mc.monitor_id = m.id AND mc.channel_id = :channel)) LIMIT 1", $params) !== null;
    }

    public function personal(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->auth->authenticate($request);
        if ($session === null) { return JsonReply::error(401, 'Nicht angemeldet.'); }
        $rows = array_values(array_filter($this->db->fetchAll("SELECT id, name, is_enabled FROM notification_channels WHERE type = 'browser' ORDER BY id"), fn (array $row): bool => $this->hasDevices($session->userId, Row::int($row, 'id')) || (Row::bool($row, 'is_enabled') && $this->eligible($session->userId, Row::int($row, 'id')))));
        return JsonReply::json(['channels' => $rows]);
    }

    public function test(#[\SensitiveParameter] Request $request): Response
    {
        $user = $this->guard($request, true);
        if ($user instanceof Response) { return $user; }
        $channel = (int) $request->attributes->getString('id');
        if ($this->db->fetchOne('SELECT id FROM browser_push_subscriptions WHERE channel_id = :channel AND user_id = :user', ['channel' => $channel, 'user' => $user]) === null) { return JsonReply::error(422, 'Zuerst diesen Browser verbinden.'); }
        $now = Timestamp::format($this->clock->now());
        $this->db->execute("INSERT INTO notification_outbox (kind, channel_id, job_name, occurred_at, status, next_attempt_at, created_at, browser_user_id) VALUES ('test', :channel, 'Testnachricht', :now, 'pending', :now, :now, :user)", ['channel' => $channel, 'now' => $now, 'user' => $user]);
        $this->audit->record($user, 'notification.browser_tested', 'channel:' . $channel);
        return JsonReply::json(['queued' => true], 202);
    }

    public function devices(#[\SensitiveParameter] Request $request): Response
    {
        $user = $this->guard($request, false);
        if ($user instanceof Response) { return $user; }
        $id = (int) $request->attributes->getString('id');
        $channel = $this->channels->find($id);
        $rows = $this->db->fetchAll('SELECT id, label, created_at, endpoint_hash FROM browser_push_subscriptions WHERE channel_id = :channel AND user_id = :user ORDER BY id', ['channel' => $id, 'user' => $user]);
        return JsonReply::json(['application_server_key' => $channel?->config['public_key'] ?? '', 'devices' => array_map(static fn (array $row): array => ['id' => Row::int($row, 'id'), 'label' => Row::string($row, 'label'), 'created_at' => Row::string($row, 'created_at'), 'fingerprint' => Row::string($row, 'endpoint_hash')], $rows)]);
    }

    public function subscribe(#[\SensitiveParameter] Request $request): Response
    {
        $user = $this->guard($request, true);
        if ($user instanceof Response) { return $user; }
        $body = JsonBody::object($request->getContent(), 8192, 5);
        if ($body === null || array_diff(array_keys($body), ['label', 'subscription']) !== []) { return JsonReply::error(422, 'Ungültiges Browser-Abonnement.'); }
        $label = $body['label'] ?? null;
        $subscription = $body['subscription'] ?? null;
        if (!is_string($label) || trim($label) === '' || preg_match('/^[^\x00-\x1f\x7f]{1,60}$/Du', $label) !== 1 || !is_array($subscription) || !is_string($subscription['endpoint'] ?? null) || !is_array($subscription['keys'] ?? null) || array_diff(array_keys($subscription), ['endpoint', 'keys', 'expirationTime']) !== []) { return JsonReply::error(422, 'Ungültiges Browser-Abonnement.'); }
        try { BrowserPushEndpoint::host($subscription['endpoint']); } catch (InvalidChannel) { return JsonReply::error(422, 'Nur Chrome-/Firefox-Push-Adressen sind erlaubt.'); }
        $keys = $subscription['keys'];
        foreach (['p256dh' => 65, 'auth' => 16] as $key => $bytes) {
            $value = $keys[$key] ?? null;
            if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) { return JsonReply::error(422, 'Ungültiger Push-Schlüssel.'); }
            $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
            if ($decoded === false || strlen($decoded) !== $bytes || ($key === 'p256dh' && $decoded[0] !== "\x04")) { return JsonReply::error(422, 'Ungültiger Push-Schlüssel.'); }
        }
        $id = (int) $request->attributes->getString('id');
        $hash = hash('sha256', $subscription['endpoint']);
        $json = json_encode(['endpoint' => $subscription['endpoint'], 'keys' => ['p256dh' => $keys['p256dh'], 'auth' => $keys['auth']], 'contentEncoding' => 'aes128gcm'], JSON_THROW_ON_ERROR);
        return $this->db->immediate(function () use ($id, $user, $hash, $label, $json): Response {
            $existing = $this->db->fetchOne('SELECT id, user_id FROM browser_push_subscriptions WHERE channel_id = :channel AND endpoint_hash = :hash', ['channel' => $id, 'hash' => $hash]);
            if ($existing !== null && Row::int($existing, 'user_id') !== $user) { return JsonReply::error(409, 'Dieses Browser-Abonnement gehört bereits zu einem anderen Konto.'); }
            if ($existing === null) {
                $count = $this->db->fetchOne('SELECT COUNT(*) AS n FROM browser_push_subscriptions WHERE channel_id = :channel AND user_id = :user', ['channel' => $id, 'user' => $user]);
                if ($count !== null && Row::int($count, 'n') >= 10) { return JsonReply::error(422, 'Höchstens zehn Browser-Geräte pro Kanal.'); }
                $this->db->execute('INSERT INTO browser_push_subscriptions (channel_id, user_id, endpoint_hash, subscription_enc, label, created_at) VALUES (:channel, :user, :hash, :secret, :label, :now)', ['channel' => $id, 'user' => $user, 'hash' => $hash, 'secret' => $this->box->encrypt($json), 'label' => $label, 'now' => Timestamp::format($this->clock->now())]);
            } else {
                $this->db->execute('UPDATE browser_push_subscriptions SET subscription_enc = :secret, label = :label WHERE id = :id', ['secret' => $this->box->encrypt($json), 'label' => $label, 'id' => Row::int($existing, 'id')]);
            }
            $this->audit->record($user, 'notification.browser_subscribed', 'channel:' . $id);
            return JsonReply::json(['subscribed' => true], 201);
        });
    }

    public function remove(#[\SensitiveParameter] Request $request): Response
    {
        $user = $this->guard($request, true);
        if ($user instanceof Response) { return $user; }
        $id = (int) $request->attributes->getString('device');
        $channel = (int) $request->attributes->getString('id');
        $row = $this->db->fetchOne('SELECT id FROM browser_push_subscriptions WHERE id = :id AND channel_id = :channel AND user_id = :user', ['id' => $id, 'channel' => $channel, 'user' => $user]);
        if ($row === null) { return JsonReply::error(404, 'Browser-Gerät nicht gefunden.'); }
        return $this->db->immediate(function () use ($id, $channel, $user): Response {
            $this->db->execute('DELETE FROM browser_push_subscriptions WHERE id = :id AND channel_id = :channel AND user_id = :user', ['id' => $id, 'channel' => $channel, 'user' => $user]);
            $this->audit->record($user, 'notification.browser_removed', 'channel:' . $channel . ' device:' . $id);
            return JsonReply::noContent();
        });
    }
}
