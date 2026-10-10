<?php

declare(strict_types=1);

namespace Meridian\Runner;

/**
 * Laufausgabe als gültiges UTF-8: ungültige Bytefolgen werden zu U+FFFD (ADR 0004 E8.3), nicht zu „?“.
 */
final class Utf8
{
    public static function scrub(#[\SensitiveParameter] string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            return mb_scrub($text, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    /** Entfernt ein am Ende angeschnittenes Mehrbyte-Zeichen (vollständige bleiben stehen). */
    public static function trimIncompleteEnd(string $text): string
    {
        $length = strlen($text);
        for ($back = 1; $back <= min(4, $length); ++$back) {
            $byte = ord($text[$length - $back]);
            if (($byte & 0xC0) === 0x80) {
                continue;
            }
            $need = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 1,
            };

            return $need > $back ? substr($text, 0, $length - $back) : $text;
        }

        return $text;
    }
}
