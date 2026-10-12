<?php
// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);
namespace Meridian\Notify;

use Meridian\Database\Connection;
use Meridian\Job\Row;
use Meridian\Security\{SecretBox, Sealed};
use Meridian\Runner\Http\AddressPolicy;
use Minishlink\WebPush\{WebPush, Subscription};

final class BrowserPushSender implements Sender
{
    /** @param (\Closure(array<string, mixed>, array<string, mixed>): WebPush)|null $clientFactory */
    public function __construct(private readonly Connection $db, private readonly SecretBox $box, private readonly ChannelNetwork $network, private readonly ?\Closure $clientFactory = null) {}

    public function send(ChannelRecord $channel, ?Sealed $secret, Message $message): void
    {
        $private = $secret?->open();
        $public = $channel->config['public_key'] ?? null;
        $contact = $channel->config['contact'] ?? null;
        if ($private === null || !is_string($public) || !is_string($contact)) { throw new SendFailed(SendFailed::AUTH); }
        $rows = $this->db->fetchAll('SELECT id, subscription_enc FROM browser_push_subscriptions WHERE channel_id = :channel', ['channel' => $channel->id]);
        if ($rows === []) { throw new SendFailed(SendFailed::REJECTED); }
        $tag = $message->deliveryId === null ? hash('sha256', $message->kind . $message->jobName . $message->occurredAt) : 'meridian-' . $channel->id . '-' . $message->deliveryId;
        $payload = json_encode(['title' => mb_strcut($message->subject(), 0, 160, 'UTF-8'), 'body' => mb_strcut($message->text(), 0, 1800, 'UTF-8'), 'url' => $message->link, 'tag' => $tag], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $hash = $message->deliveryId === null ? hash('sha256', $payload) : hash('sha256', $tag . ':' . $message->occurredAt);
        $failed = false;
        $delivered = false;
        foreach ($rows as $row) {
            $id = Row::int($row, 'id');
            if ($this->db->fetchOne('SELECT message_hash FROM browser_push_deliveries WHERE subscription_id = :id AND message_hash = :hash', ['id' => $id, 'hash' => $hash]) !== null) { $delivered = true; continue; }
            try {
                $data = json_decode($this->box->decrypt(Row::string($row, 'subscription_enc')), true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($data) || !is_string($data['endpoint'] ?? null)) { throw new SendFailed(SendFailed::REJECTED); }
                $host = BrowserPushEndpoint::host($data['endpoint']);
                $addresses = $this->network->pin($host, 443);
                foreach ($addresses as $ip) { if (AddressPolicy::classify($ip) !== null) { throw new SendFailed(SendFailed::REJECTED); } }
                $resolve = $host . ':443:' . implode(',', array_map(static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $addresses));
                $auth = ['VAPID' => ['subject' => 'mailto:' . $contact, 'publicKey' => $public, 'privateKey' => $private]];
                $options = ['handler' => new \GuzzleHttp\Handler\CurlHandler(), 'allow_redirects' => false, 'verify' => true, 'proxy' => '', 'connect_timeout' => 5, 'curl' => [CURLOPT_RESOLVE => [$resolve], CURLOPT_PROTOCOLS_STR => 'https']];
                $push = $this->clientFactory === null ? new WebPush($auth, ['TTL' => 300], 8, $options) : ($this->clientFactory)($auth, $options);
                $report = $push->sendOneNotification(Subscription::create($data), $payload);
                if ($report->isSubscriptionExpired()) { $this->db->execute('DELETE FROM browser_push_subscriptions WHERE id = :id', ['id' => $id]); continue; }
                if (!$report->isSuccess()) { $failed = true; continue; }
                $this->db->execute('INSERT OR IGNORE INTO browser_push_deliveries (subscription_id, message_hash, delivered_at) VALUES (:id, :hash, datetime(\'now\'))', ['id' => $id, 'hash' => $hash]);
                $delivered = true;
            } catch (\Throwable) { $failed = true; }
        }
        $this->db->execute("DELETE FROM browser_push_deliveries WHERE delivered_at < datetime('now', '-7 days')");
        if ($failed || !$delivered) { throw new SendFailed(SendFailed::CONNECT); }
    }
}
