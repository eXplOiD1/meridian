<?php

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Runner\Http\HttpTransport;
use Meridian\Runner\Http\ParsedUrl;
use Meridian\Runner\Http\PinnedTarget;
use Meridian\Runner\Http\TransportError;
use Meridian\Runner\Http\TransportRequest;
use Meridian\Security\Sealed;

/**
 * Telegram über die Bot-API (`https://api.telegram.org`, fest; kein frei wählbarer Server). Der Bot-Token steht im Pfad der
 * Anfrage und nur dort; er wird nie protokolliert, ausgegeben oder in Fehlertexten genannt (ParsedUrl hält den Pfad versiegelt).
 */
final class TelegramSender implements Sender
{
    public const HOST = 'api.telegram.org';
    public const TIMEOUT_MS = 15_000;

    public function __construct(private readonly ChannelNetwork $network, private readonly HttpTransport $transport)
    {
    }

    #[\Override]
    public function send(ChannelRecord $channel, ?Sealed $secret, Message $message): void
    {
        /** @var mixed $chat */
        $chat = $channel->config['chat_id'] ?? null;
        $token = $secret?->open();
        if (!is_string($chat) || $token === null || $token === '') {
            throw new SendFailed(SendFailed::AUTH);
        }
        $addresses = $this->network->pin(self::HOST, 443);
        $body = json_encode(['chat_id' => $chat, 'text' => $message->subject() . "\n\n" . $message->text(), 'disable_web_page_preview' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $target = new PinnedTarget(new ParsedUrl('https', self::HOST, 443, false, '/bot' . $token . '/sendMessage', null), $addresses);
        $response = $this->transport->send(new TransportRequest('POST', $target, [['Content-Type', 'application/json; charset=utf-8']], $body, self::TIMEOUT_MS), new NullHeartbeat());

        if ($response->error !== null) {
            throw new SendFailed($response->error === TransportError::Tls ? SendFailed::TLS : SendFailed::CONNECT);
        }
        if ($response->status === 401 || $response->status === 403 || $response->status === 404) {
            throw new SendFailed(SendFailed::AUTH);
        }
        if ($response->status < 200 || $response->status > 299) {
            throw new SendFailed(SendFailed::REJECTED);
        }
    }
}
