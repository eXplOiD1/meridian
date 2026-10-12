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
 * ntfy: `POST {server}/{thema}` mit Titel und Priorität in Headern, Text im Body (ADR 0007). Wie bei HTTP-Jobs wird zur geprüften
 * Adresse verbunden ({@see ChannelNetwork}), TLS wird immer geprüft; das Token geht nur im Authorization-Header hinaus.
 */
final class NtfySender implements Sender
{
    public const TIMEOUT_MS = 15_000;

    public function __construct(private readonly ChannelNetwork $network, private readonly HttpTransport $transport)
    {
    }

    #[\Override]
    public function send(ChannelRecord $channel, ?Sealed $secret, Message $message): void
    {
        /** @var mixed $server */
        $server = $channel->config['server'] ?? null;
        /** @var mixed $topic */
        $topic = $channel->config['topic'] ?? null;
        /** @var mixed $priority */
        $priority = $channel->config['priority'] ?? 3;
        $parts = is_string($server) ? parse_url($server) : false;
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !is_string($topic) || !is_int($priority)) {
            throw new SendFailed(SendFailed::REJECTED);
        }
        $scheme = $parts['scheme'] === 'http' ? 'http' : 'https';
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $addresses = $this->network->pin($host, $port);

        $headers = [['Title', self::headerValue($message->subject())], ['Priority', (string) $priority], ['Tags', $message->kind === 'failure' ? 'rotating_light' : ($message->kind === 'recovery' ? 'white_check_mark' : 'bell')], ['Content-Type', 'text/plain; charset=utf-8']];
        $token = $secret?->open();
        if ($token !== null && $token !== '') {
            $headers[] = ['Authorization', 'Bearer ' . $token];
        }
        $target = new PinnedTarget(new ParsedUrl($scheme, $host, $port, filter_var($host, FILTER_VALIDATE_IP) !== false, '/' . $topic, null), $addresses);
        $response = $this->transport->send(new TransportRequest('POST', $target, $headers, $message->text(), self::TIMEOUT_MS), new NullHeartbeat());

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

    /** Nicht-ASCII-Zeichen im Header als RFC 2047 (ntfy versteht das), Steuerzeichen nie. */
    private static function headerValue(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';

        return preg_match('/^[\x20-\x7E]*$/D', $clean) === 1 ? $clean : '=?UTF-8?B?' . base64_encode($clean) . '?=';
    }
}
