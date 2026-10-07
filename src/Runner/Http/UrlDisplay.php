<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Security\SecretMasker;
use Meridian\Settings\DisplayPathMode;

/**
 * Die einzige Stelle, die die Anzeige-URL (`display_url`) eines HTTP-Jobs bildet (docs/decisions/0003, E2).
 *
 * Ausnahme von „keine Geheimnisse in Antworten“, sicher durch Konstruktion: eine Allowlist dessen, was sichtbar
 * sein darf. Jeder andere Bestandteil wird durch den festen Platzhalter `••••` ersetzt, unabhängig von seiner
 * Länge. Aufgerufen nur beim Ersetzen der Anfrage; lesende Endpunkte geben den gespeicherten Text aus und bilden
 * ihn nie neu (und entschlüsseln nie).
 *
 * Sichtbar: Schema, Host, Port; im Modus `auto` Pfadsegmente aus harmlosen Kleinbuchstaben-Wörtern und
 * Query-Namen nach fester Regel. Query-Werte nie. Leere Pfadsegmente (`/`, `/api/`) bleiben leer: sie tragen
 * keinen Inhalt.
 */
final class UrlDisplay
{
    /** Version der Maskierungsregel (`config_json.http.display_v`). Strengere Regel → neue Version. */
    public const VERSION = 1;

    public const PLACEHOLDER = SecretMasker::MASK;

    private const SEGMENT = '/^[a-z]+(?:[-_][a-z]+){0,3}(?:\.(?:php|html?|aspx?|jsp|cgi|json|xml|txt))?$/D';
    private const SEGMENT_MAX_LENGTH = 24;
    private const WORD_MAX_LENGTH = 16;

    private const QUERY_NAME = '/^[a-z][a-z0-9_.\-]{0,31}$/D';

    public static function fromParsed(#[\SensitiveParameter] ParsedUrl $url, DisplayPathMode $mode): string
    {
        return $url->origin() . self::path($url->path(), $mode) . self::query($url->query(), $mode);
    }

    /**
     * Darf dieses (rohe, nicht dekodierte) Pfadsegment im Modus `auto` sichtbar sein?
     * Nur Kleinbuchstaben-Wörter: höchstens vier Wortteile mit `-`/`_`, optional eine Endung aus fester Liste,
     * Länge ≤ 24, kein Wortteil länger als 16. Damit fallen Ziffern, Großbuchstaben, `%`, `;`, `:`, `=`, `~`, `@`
     * und damit fast alle Tokens heraus.
     */
    public static function isVisibleSegment(string $segment): bool
    {
        if (strlen($segment) > self::SEGMENT_MAX_LENGTH || preg_match(self::SEGMENT, $segment) !== 1) {
            return false;
        }
        $words = preg_split('/[-_.]/', $segment);
        foreach ($words === false ? [$segment] : $words as $word) {
            if (strlen($word) > self::WORD_MAX_LENGTH) {
                return false;
            }
        }

        return true;
    }

    /** Darf dieser (rohe) Query-Name im Modus `auto` sichtbar sein? */
    public static function isVisibleQueryName(string $name): bool
    {
        return preg_match(self::QUERY_NAME, $name) === 1;
    }

    private static function path(string $path, DisplayPathMode $mode): string
    {
        if ($path === '') {
            return '';
        }
        if ($mode === DisplayPathMode::Hidden) {
            return '/' . self::PLACEHOLDER;
        }

        $segments = explode('/', substr($path, 1));
        $shown = [];
        foreach ($segments as $segment) {
            $shown[] = $segment === '' || self::isVisibleSegment($segment) ? $segment : self::PLACEHOLDER;
        }

        return '/' . implode('/', $shown);
    }

    private static function query(?string $query, DisplayPathMode $mode): string
    {
        if ($query === null) {
            return '';
        }
        if ($mode === DisplayPathMode::Hidden) {
            return '?' . self::PLACEHOLDER;
        }

        $shown = [];
        foreach (explode('&', $query) as $pair) {
            $eq = strpos($pair, '=');
            $name = $eq === false ? $pair : substr($pair, 0, $eq);
            // Werte immer verborgen, auch leere und fehlende: `a&b=` → `a=••••&b=••••`.
            $shown[] = (self::isVisibleQueryName($name) ? $name : self::PLACEHOLDER) . '=' . self::PLACEHOLDER;
        }

        return '?' . implode('&', $shown);
    }
}
