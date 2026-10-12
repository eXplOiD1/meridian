<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Security\Sealed;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * E-Mail über SMTP mit PHPMailer (ADR 0007 N1, N7). Verbunden wird zur **geprüften** Adresse ({@see ChannelNetwork}); der
 * Name des Servers steht nur in `peer_name`, damit die TLS-Prüfung gegen den Namen läuft (Prüfung nie aus, kein selbstsigniertes
 * Zertifikat). Meldungen von PHPMailer oder Server tragen Host und Benutzer und werden nie weitergegeben: nur feste Texte.
 */
final class SmtpSender implements Sender
{
    public const TIMEOUT_SECONDS = 15;

    /**
     * @param (\Closure(): PHPMailer)|null $factory nur für Tests
     */
    public function __construct(private readonly ChannelNetwork $network, private readonly ?\Closure $factory = null)
    {
    }

    #[\Override]
    public function send(ChannelRecord $channel, ?Sealed $secret, Message $message): void
    {
        $config = $channel->config;
        /** @var mixed $host */
        $host = $config['host'] ?? null;
        /** @var mixed $port */
        $port = $config['port'] ?? null;
        /** @var mixed $encryption */
        $encryption = $config['encryption'] ?? 'starttls';
        /** @var mixed $from */
        $from = $config['from'] ?? null;
        /** @var mixed $to */
        $to = $config['to'] ?? null;
        if (!is_string($host) || !is_int($port) || !is_string($from) || !is_array($to) || $to === []) {
            throw new SendFailed(SendFailed::REJECTED);
        }
        $addresses = $this->network->pin($host, $port);
        // IPv4 zuerst: PHPMailer versteht „Adresse:Port“ nur dort verlässlich.
        usort($addresses, static fn (string $a, string $b): int => (str_contains($a, ':') <=> str_contains($b, ':')));
        $address = $addresses[0];

        $mail = $this->factory === null ? new PHPMailer(true) : ($this->factory)();
        try {
            $mail->isSMTP();
            $mail->Host = str_contains($address, ':') ? '[' . $address . ']' : $address;
            $mail->Port = $port;
            $mail->Timeout = self::TIMEOUT_SECONDS;
            $mail->SMTPDebug = 0;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->XMailer = ' ';
            $mail->SMTPAutoTLS = false;
            $mail->SMTPSecure = match ($encryption) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            $mail->SMTPOptions = ['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
            /** @var mixed $username */
            $username = $config['username'] ?? null;
            if (is_string($username) && $username !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $username;
                $mail->Password = $secret === null ? '' : $secret->open();
            }
            /** @var mixed $fromName */
            $fromName = $config['from_name'] ?? null;
            $mail->setFrom($from, is_string($fromName) ? $fromName : '');
            /** @var mixed $recipient */
            foreach ($to as $recipient) {
                if (is_string($recipient)) {
                    $mail->addAddress($recipient);
                }
            }
            $mail->isHTML(false);
            $mail->Subject = $message->subject();
            $mail->Body = $message->text();
            $mail->send();
        } catch (MailException $e) {
            throw new SendFailed(self::reason($e->getMessage()));
        } catch (\Throwable) {
            throw new SendFailed(SendFailed::CONNECT);
        } finally {
            try {
                $mail->smtpClose();
            } catch (\Throwable) {
                // Schließen ist best effort.
            }
        }
    }

    /**
     * Ordnet die (englische) Meldung von PHPMailer einem festen Text zu; die Meldung selbst wird verworfen.
     */
    private static function reason(string $raw): string
    {
        $text = strtolower($raw);

        return match (true) {
            str_contains($text, 'authenticate') || str_contains($text, 'auth') && str_contains($text, 'fail') => SendFailed::AUTH,
            str_contains($text, 'tls') || str_contains($text, 'ssl') || str_contains($text, 'crypto') => SendFailed::TLS,
            str_contains($text, 'recipient') || str_contains($text, 'data not accepted') || str_contains($text, 'from address') || str_contains($text, 'invalid address') || str_contains($text, 'sender') => SendFailed::REJECTED,
            default => SendFailed::CONNECT,
        };
    }
}
