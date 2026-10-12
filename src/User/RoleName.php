<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\User;

/**
 * Name und Beschreibung einer eigenen Rolle (ADR 0006 §4, F9). Ablehnen, nie trimmen oder umdeuten.
 *
 * Name: 2–40 Zeichen aus Buchstaben, Ziffern, Leerzeichen, Punkt, Unterstrich, Bindestrich; Anfang und Ende Buchstabe
 * oder Ziffer. Beschreibung: 1–200 Zeichen ohne Steuer- oder Formatzeichen (keine Zeilenumbrüche, keine
 * Richtungszeichen); `null` heißt „keine Beschreibung“.
 */
final class RoleName
{
    public const MAX_NAME = 40;
    public const MAX_DESCRIPTION = 200;

    public const MESSAGE_NAME = 'Name: 2 bis 40 Zeichen aus Buchstaben, Ziffern, Leerzeichen, Punkt, Unterstrich und Bindestrich; am Anfang und Ende ein Buchstabe oder eine Ziffer.';
    public const MESSAGE_DESCRIPTION = 'Beschreibung: 1 bis 200 Zeichen ohne Zeilenumbrüche und Steuerzeichen, oder null für keine Beschreibung.';

    public static function isValid(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,38}[\p{L}\p{N}]$/u', $name) === 1;
    }

    public static function isValidDescription(mixed $description): bool
    {
        if ($description === null) {
            return true;
        }
        if (!is_string($description) || $description === '' || preg_match('//u', $description) !== 1) {
            return false;
        }

        return mb_strlen($description) <= self::MAX_DESCRIPTION && preg_match('/[\p{Cc}\p{Cf}\p{Cs}\p{Co}\p{Cn}\x{2028}\x{2029}]/u', $description) !== 1;
    }
}
