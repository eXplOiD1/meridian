<?php
// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);
namespace Meridian\Notify;

final class BrowserPushEndpoint
{
    public static function host(string $endpoint): string
    {
        if (strlen($endpoint) > 2048 || preg_match('#^https://(fcm\.googleapis\.com|updates\.push\.services\.mozilla\.com)/[A-Za-z0-9_./~%:-]+$#D', $endpoint, $match) !== 1) {
            throw new InvalidChannel('subscription', 'Nur gültige Chrome-/Firefox-Push-Adressen über HTTPS sind erlaubt.');
        }
        return $match[1];
    }
}
