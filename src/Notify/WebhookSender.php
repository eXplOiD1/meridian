<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Runner\Http\HttpTransport;
use Meridian\Runner\Http\ParsedUrl;
use Meridian\Runner\Http\PinnedTarget;
use Meridian\Runner\Http\TransportError;
use Meridian\Runner\Http\TransportRequest;
use Meridian\Security\Sealed;

/**
 * Webhook: `POST {url}` mit einem JSON-Körper (Ereignis, Jobname, Status, Zeit, Hinweis, Link, Text) und optional
 * `Authorization: Bearer {token}`. Ziel wie bei allen Kanälen geprüft und festgehalten, TLS immer geprüft; die Antwort
 * des Empfängers wird nie gespeichert oder weitergegeben (ADR 0007).
 */
final class WebhookSender implements Sender
{
    public const TIMEOUT_MS = 15_000;

    public function __construct(private readonly ChannelNetwork $network, private readonly HttpTransport $transport)
    {
    }

    #[\Override]
    public function send(ChannelRecord $channel, ?Sealed $secret, Message $message): void
    {
        /** @var mixed $url */
        $url = $channel->config['url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new SendFailed(SendFailed::REJECTED);
        }
        $scheme = $parts['scheme'] === 'http' ? 'http' : 'https';
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $addresses = $this->network->pin($host, $port);

        $headers = [['Content-Type', 'application/json; charset=utf-8'], ['Accept', 'application/json, text/plain, */*']];
        $token = $secret?->open();
        if ($token !== null && $token !== '') {
            $headers[] = ['Authorization', 'Bearer ' . $token];
        }
        $body = json_encode([
            'event' => $message->kind,
            'job' => $message->kind === 'test' ? null : $message->jobName,
            'status' => $message->runStatus,
            'failures' => $message->failures,
            'occurred_at' => $message->occurredAt,
            'detail' => $message->detail,
            'link' => $message->link,
            'text' => $message->subject() . "\n" . $message->text(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $target = new PinnedTarget(new ParsedUrl($scheme, $host, $port, filter_var($host, FILTER_VALIDATE_IP) !== false, $parts['path'] ?? '', null), $addresses);
        $response = $this->transport->send(new TransportRequest('POST', $target, $headers, $body, self::TIMEOUT_MS), new NullHeartbeat());

        if ($response->error !== null) {
            throw new SendFailed($response->error === TransportError::Tls ? SendFailed::TLS : SendFailed::CONNECT);
        }
        if ($response->status === 401 || $response->status === 403) {
            throw new SendFailed(SendFailed::AUTH);
        }
        if ($response->status < 200 || $response->status > 299) {
            throw new SendFailed(SendFailed::REJECTED);
        }
    }
}
