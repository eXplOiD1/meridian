<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Auth\CsrfGuard;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
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
 * Benachrichtigungskanäle (ADR 0007 N1–N3):
 *
 *   GET    /api/notifications/channels            alle Kanäle, ohne Geheimnis (nur `has_secret`)        — settings.manage
 *   POST   /api/notifications/channels            {type, name, enabled, …Felder des Typs}               — settings.manage
 *   PUT    /api/notifications/channels/{id}       Art unveränderlich; Passwort/Token nur als Ganzes     — settings.manage
 *   DELETE /api/notifications/channels/{id}                                                             — settings.manage
 *   POST   /api/notifications/channels/{id}/test  legt eine Testnachricht in den Ausgang                — settings.manage
 *   GET    /api/notifications/channel-names       Auswahl im Job-Editor: id, name, type                 — jobs.view
 *
 * Ablauf: Sitzung (401) → bei Änderungen CSRF und Herkunft (403) → Recht (403) → Eingabe (422) → unbekannte ID (404) → Aktion →
 * Audit (`notification.channel_added/_updated/_removed/_tested`, nur `channel:ID Name` und Feldnamen).
 * Der Versand selbst läuft nie in der Anfrage, sondern im Zusteller ({@see Dispatcher}); die Antwort auf „Test“ meldet nur „eingereiht“.
 */
final class NotificationChannelController
{
    private const MAX_BODY = 8192;

    public function __construct(
        private readonly SessionAuth $sessionAuth,
        private readonly CsrfGuard $csrf,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly AuditLog $audit,
        private readonly ChannelStore $store,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SecretMasker $masker,
    ) {
    }

    public function register(Kernel $kernel): void
    {
        $kernel->get('notification_channels_list', '/api/notifications/channels', $this->list(...));
        $kernel->post('notification_channels_add', '/api/notifications/channels', $this->add(...));
        $kernel->put('notification_channels_update', '/api/notifications/channels/{id}', $this->update(...), ['id' => '[1-9][0-9]{0,17}']);
        $kernel->delete('notification_channels_remove', '/api/notifications/channels/{id}', $this->remove(...), ['id' => '[1-9][0-9]{0,17}']);
        $kernel->post('notification_channels_test', '/api/notifications/channels/{id}/test', $this->test(...), ['id' => '[1-9][0-9]{0,17}']);
        $kernel->get('notification_channel_names', '/api/notifications/channel-names', $this->names(...));
    }

    public function list(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageSettings);

        $last = $this->store->lastDeliveries();
        $channels = [];
        foreach ($this->store->all() as $channel) {
            $channels[] = $this->present($channel) + ['last_delivery' => $last[$channel->id] ?? null];
        }

        return JsonReply::json(['channels' => $channels, 'max_channels' => ChannelStore::MAX_CHANNELS]);
    }

    public function names(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        $grants = $this->users->grantsFor($session->userId);
        if ($this->access->scope($grants, Permission::ViewJobs)->isEmpty()) {
            $this->access->require($grants, Permission::ViewJobs);
        }
        $out = [];
        foreach ($this->store->all() as $channel) {
            $out[] = ['id' => $channel->id, 'name' => $this->masker->mask($channel->name), 'type' => $channel->type->value, 'enabled' => $channel->isEnabled];
        }

        return JsonReply::json(['channels' => $out]);
    }

    public function add(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->guarded($request);
        if ($session instanceof Response) {
            return $session;
        }
        try {
            $draft = ChannelValidator::validate(self::body($request), null);
            $record = $this->store->add($draft, $session, $this->audit);
        } catch (InvalidChannel $e) {
            throw ValidationFailed::field($e->field, $e->getMessage());
        }

        return JsonReply::json(['channel' => $this->present($record)], 201);
    }

    public function update(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->guarded($request);
        if ($session instanceof Response) {
            return $session;
        }
        $existing = $this->store->find((int) $request->attributes->getString('id'));
        try {
            $draft = ChannelValidator::validate(self::body($request), $existing?->type);
            $record = $existing === null ? null : $this->store->update($existing->id, $draft, $session, $this->audit);
        } catch (InvalidChannel $e) {
            throw ValidationFailed::field($e->field, $e->getMessage());
        }
        if ($record === null) {
            return JsonReply::error(404, 'Kanal nicht gefunden.');
        }

        return JsonReply::json(['channel' => $this->present($record)]);
    }

    public function remove(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->guarded($request);
        if ($session instanceof Response) {
            return $session;
        }
        if (!$this->store->remove((int) $request->attributes->getString('id'), $session, $this->audit)) {
            return JsonReply::error(404, 'Kanal nicht gefunden.');
        }

        return new Response(null, 204, ['Cache-Control' => 'no-store']);
    }

    public function test(#[\SensitiveParameter] Request $request): Response
    {
        $session = $this->guarded($request);
        if ($session instanceof Response) {
            return $session;
        }
        $channel = $this->store->find((int) $request->attributes->getString('id'));
        if ($channel === null) {
            return JsonReply::error(404, 'Kanal nicht gefunden.');
        }
        $now = Timestamp::format($this->clock->now());
        $this->db->immediate(function () use ($channel, $now, $session): void {
            $this->db->execute(
                "INSERT INTO notification_outbox (kind, channel_id, job_name, occurred_at, status, next_attempt_at, created_at)
                 VALUES ('test', :channel, 'Testnachricht', :now, 'pending', :now, :now)",
                ['channel' => $channel->id, 'now' => $now],
            );
            $this->audit->record($session, 'notification.channel_tested', 'channel:' . $channel->id . ' ' . $channel->name);
        });

        return JsonReply::json(['queued' => true, 'message' => 'Testnachricht eingereiht. Der Dienst worker-http stellt sie innerhalb einer Minute zu; das Ergebnis steht danach bei „Letzte Zustellung“ am Kanal.'], 202);
    }

    /**
     * Sitzung, CSRF/Herkunft und `settings.manage`; gibt die Benutzer-ID oder die fertige Fehlerantwort zurück.
     */
    private function guarded(Request $request): int|Response
    {
        $session = $this->sessionAuth->authenticate($request);
        if ($session === null) {
            return JsonReply::error(401, 'Nicht angemeldet.');
        }
        if (!$this->csrf->check($request, $session->token)) {
            return JsonReply::csrfFailed();
        }
        $this->access->require($this->users->grantsFor($session->userId), Permission::ManageSettings);

        return $session->userId;
    }

    /**
     * @return array<mixed>
     */
    private static function body(#[\SensitiveParameter] Request $request): array
    {
        $data = JsonBody::object($request->getContent(), self::MAX_BODY, 3);
        $message = 'JSON-Objekt mit den Feldern des Kanals erwartet (höchstens 8 KiB).';
        if ($data === null) {
            throw ValidationFailed::field('body', $message);
        }
        if ($data !== [] && array_is_list($data)) {
            throw ValidationFailed::field('body', $message);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChannelRecord $channel): array
    {
        $config = [];
        /** @var mixed $value */
        foreach ($channel->config as $key => $value) {
            if ($key === 'public_key') { continue; }
            if (is_array($value)) {
                /** @var list<string> $strings */
                $strings = array_values($value);
                $config[$key] = array_map($this->masker->mask(...), $strings);
            } elseif (is_string($value)) {
                $config[$key] = $this->masker->mask($value);
            } elseif (is_int($value) || is_bool($value) || $value === null) {
                $config[$key] = $value;
            }
        }

        return [
            'id' => $channel->id,
            'name' => $this->masker->mask($channel->name),
            'type' => $channel->type->value,
            'enabled' => $channel->isEnabled,
            'config' => $config,
            'has_secret' => $channel->hasSecret,
            'created_at' => $channel->createdAt,
            'updated_at' => $channel->updatedAt,
        ];
    }
}
