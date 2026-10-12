<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Http\ValidationFailed;

/** Empty lists inherit the recipients from the parent category or channel. */
final class Recipients
{
    /** @return list<string> */
    public static function validate(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 10) {
            throw ValidationFailed::field($field, 'Empfänger: Liste von höchstens 10 E-Mail-Adressen. Leer = erben.');
        }
        $addresses = [];
        /** @var mixed $address */
        foreach ($value as $address) {
            if (!is_string($address) || strlen($address) > 254 || preg_match('/^[\x21-\x7E]+$/D', $address) !== 1 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationFailed::field($field, 'Keine gültige E-Mail-Adresse.');
            }
            $addresses[] = $address;
        }
        return array_values(array_unique($addresses));
    }

    /** @return list<string> */
    public static function decode(string $json): array
    {
        return self::validate(json_decode($json, true, 4, JSON_THROW_ON_ERROR), 'recipients');
    }
}
