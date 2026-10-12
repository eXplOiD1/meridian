<?php
// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);
namespace Meridian\Notify;

use Meridian\Runner\Http\{HttpTransport, ParsedUrl, PinnedTarget, TransportRequest, TransportError};
use Meridian\Security\Sealed;

final class IntegrationSender implements Sender
{
    public function __construct(private readonly ChannelNetwork $network, private readonly HttpTransport $transport) {}

    public function send(ChannelRecord $channel, ?Sealed $secret, Message $message): void
    {
        $credential = $secret?->open();
        if ($credential === null || $credential === '') { throw new SendFailed(SendFailed::AUTH); }
        $text = $message->subject() . "\n\n" . $message->text();
        $headers = [];
        $form = false;
        switch ($channel->type) {
            case ChannelType::Discord:
                $url = ChannelValidator::integrationWebhook($channel->type, $credential);
                $payload = ['content' => mb_strcut($text, 0, 1800, 'UTF-8'), 'allowed_mentions' => ['parse' => []]];
                break;
            case ChannelType::Slack:
                $url = ChannelValidator::integrationWebhook($channel->type, $credential);
                $payload = ['text' => mb_strcut($text, 0, 7000, 'UTF-8'), 'mrkdwn' => false, 'unfurl_links' => false, 'unfurl_media' => false];
                break;
            case ChannelType::Pushover:
                $keys = json_decode($credential, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($keys) || !is_string($keys['token'] ?? null) || !is_string($keys['user'] ?? null)) { throw new SendFailed(SendFailed::AUTH); }
                $url = 'https://api.pushover.net/1/messages.json';
                $payload = ['token' => $keys['token'], 'user' => $keys['user'], 'title' => mb_substr($message->subject(), 0, 250), 'message' => mb_substr($message->text(), 0, 1024), 'priority' => $channel->config['priority'] ?? 0];
                $form = true;
                break;
            case ChannelType::HomeAssistant:
                $server = $channel->config['server'] ?? null;
                $service = $channel->config['service'] ?? null;
                if (!is_string($server) || !is_string($service) || preg_match('/^(persistent_notification\.create|notify\.[a-z0-9_]{1,80})$/D', $service) !== 1) { throw new SendFailed(SendFailed::REJECTED); }
                $url = rtrim($server, '/') . '/api/services/' . str_replace('.', '/', $service);
                $payload = ['title' => $message->subject(), 'message' => $message->text()];
                $headers[] = ['Authorization', 'Bearer ' . $credential];
                break;
            default: throw new SendFailed(SendFailed::REJECTED);
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { throw new SendFailed(SendFailed::REJECTED); }
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $target = new PinnedTarget(new ParsedUrl($parts['scheme'], $host, $port, filter_var($host, FILTER_VALIDATE_IP) !== false, $parts['path'] ?? '/', null), $this->network->pin($host, $port));
        $headers[] = ['Content-Type', $form ? 'application/x-www-form-urlencoded' : 'application/json; charset=utf-8'];
        $body = $form ? http_build_query($payload) : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $response = $this->transport->send(new TransportRequest('POST', $target, $headers, $body, 15000), new NullHeartbeat());
        if ($response->error !== null) { throw new SendFailed($response->error === TransportError::Tls ? SendFailed::TLS : SendFailed::CONNECT); }
        if ($response->status === 401 || $response->status === 403) { throw new SendFailed(SendFailed::AUTH); }
        if ($response->status < 200 || $response->status > 299) { throw new SendFailed(SendFailed::REJECTED); }
        if ($channel->type === ChannelType::Pushover) {
            $reply = json_decode($response->body(), true);
            if (!is_array($reply) || ($reply['status'] ?? null) !== 1) { throw new SendFailed(SendFailed::REJECTED); }
        }
    }
}
