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
 * Sichtbar: Schema, Host, Port; im Modus `auto` Pfadsegmente und Query-Namen aus harmlosen Kleinbuchstaben-Wörtern
 * (dieselbe Wortregel, keine Ziffern). Query-Werte nie; ein Query-Teil ohne `=` ist selbst ein Wert und wird ganz
 * zu `••••`. Leere Pfadsegmente (`/`, `/api/`) bleiben leer: sie tragen keinen Inhalt.
 *
 * Version 2 (S11-Review): Query-Namen ohne Ziffern (v1 ließ `?e3b0c442…` und `?<token>=1` sichtbar). Gespeicherte
 * v1-Anzeigen verschärft {@see self::upgradeStored()} ohne Entschlüsseln.
 */
final class UrlDisplay
{
    /** Version der Maskierungsregel (`config_json.http.display_v`). Strengere Regel → neue Version. */
    public const VERSION = 2;

    public const PLACEHOLDER = SecretMasker::MASK;

    private const SEGMENT = '/^[a-z]+(?:[-_][a-z]+){0,3}(?:\.(?:php|html?|aspx?|jsp|cgi|json|xml|txt))?$/D';
    private const SEGMENT_MAX_LENGTH = 24;
    private const WORD_MAX_LENGTH = 16;

    /** Query-Name: wie ein Pfadsegment ohne Endung, Wortteile auch mit `.` getrennt (`page.size`). */
    private const QUERY_NAME = '/^[a-z]+(?:[-_.][a-z]+){0,3}$/D';

    public static function fromParsed(#[\SensitiveParameter] ParsedUrl $url, DisplayPathMode $mode): string
    {
        return $url->origin() . self::path($url->path(), $mode) . self::query($url->query(), $mode);
    }

    /**
     * Verschärft eine **gespeicherte** Anzeige-URL auf den Modus `hidden`, ohne die Anfrage zu entschlüsseln
     * (docs/decisions/0003, E3): Ursprung + `/••••`, wenn ein Pfad da war, + `?••••`, wenn eine Query da war — genau
     * das, was {@see self::fromParsed()} im Modus `hidden` liefert. Nur strenger, nie lockerer: Passt der Text nicht
     * zum Ursprung des Jobs, werden Pfad und Query beide verborgen.
     *
     * @param string $origin Schema + Host (+ Port) aus `config_json.http.target`
     */
    public static function hideStored(string $displayUrl, string $origin): string
    {
        $rest = str_starts_with($displayUrl, $origin) ? substr($displayUrl, strlen($origin)) : null;
        if ($rest === null || ($rest !== '' && $rest[0] !== '/' && $rest[0] !== '?')) {
            return $origin . '/' . self::PLACEHOLDER . '?' . self::PLACEHOLDER;
        }
        $q = strpos($rest, '?');
        $path = $q === false ? $rest : substr($rest, 0, $q);

        return $origin . ($path === '' ? '' : '/' . self::PLACEHOLDER) . ($q === false ? '' : '?' . self::PLACEHOLDER);
    }

    /**
     * Darf dieses (rohe, nicht dekodierte) Pfadsegment im Modus `auto` sichtbar sein?
     * Nur Kleinbuchstaben-Wörter: höchstens vier Wortteile mit `-`/`_`, optional eine Endung aus fester Liste,
     * Länge ≤ 24, kein Wortteil länger als 16. Damit fallen Ziffern, Großbuchstaben, `%`, `;`, `:`, `=`, `~`, `@`
     * und damit fast alle Tokens heraus.
     */
    public static function isVisibleSegment(string $segment): bool
    {
        return strlen($segment) <= self::SEGMENT_MAX_LENGTH && preg_match(self::SEGMENT, $segment) === 1 && self::wordsShort($segment);
    }

    /**
     * Darf dieser (rohe) Query-Name im Modus `auto` sichtbar sein? Dieselbe Wortregel wie für Pfadsegmente: nur
     * Kleinbuchstaben, höchstens vier Wortteile mit `-`, `_` oder `.`, Länge ≤ 24, kein Wortteil länger als 16.
     * Ziffern, Großbuchstaben und `%` machen den Namen unsichtbar (Hex-/base36-Tokens als Name).
     */
    public static function isVisibleQueryName(string $name): bool
    {
        return strlen($name) <= self::SEGMENT_MAX_LENGTH && preg_match(self::QUERY_NAME, $name) === 1 && self::wordsShort($name);
    }

    /**
     * Verschärft eine gespeicherte Anzeige-URL einer älteren Regel auf die aktuelle, ohne zu entschlüsseln:
     * v1 → v2 ersetzt jeden Query-Namen, der die neue Regel nicht erfüllt, durch `••••` (aus `name=••••` wird
     * `••••`). Der Pfad blieb gleich. Passt der Text nicht zur erwarteten Form oder zum Ursprung, werden Pfad und
     * Query verborgen (nur strenger, nie lockerer).
     *
     * @param string $origin Schema + Host (+ Port) aus `config_json.http.target`
     */
    public static function upgradeStored(string $displayUrl, string $origin, int $fromVersion): string
    {
        if ($fromVersion === self::VERSION) {
            return $displayUrl;
        }
        $rest = str_starts_with($displayUrl, $origin) ? substr($displayUrl, strlen($origin)) : null;
        if ($fromVersion !== 1 || $rest === null || ($rest !== '' && $rest[0] !== '/' && $rest[0] !== '?')) {
            return self::hideStored($displayUrl, $origin);
        }
        $q = strpos($rest, '?');
        if ($q === false) {
            return $displayUrl;
        }
        $shown = [];
        foreach (explode('&', substr($rest, $q + 1)) as $pair) {
            if ($pair === self::PLACEHOLDER) {
                $shown[] = $pair;
                continue;
            }
            $suffix = '=' . self::PLACEHOLDER;
            if (!str_ends_with($pair, $suffix)) {
                // Keine v1-Form: im Zweifel alles hinter dem Pfad verbergen.
                return $origin . substr($rest, 0, $q) . '?' . self::PLACEHOLDER;
            }
            $name = substr($pair, 0, -strlen($suffix));
            $shown[] = $name === self::PLACEHOLDER ? $pair : (self::isVisibleQueryName($name) ? $pair : self::PLACEHOLDER . $suffix);
        }

        return $origin . substr($rest, 0, $q) . '?' . implode('&', $shown);
    }

    private static function wordsShort(string $text): bool
    {
        $words = preg_split('/[-_.]/', $text);
        foreach ($words === false ? [$text] : $words as $word) {
            if (strlen($word) > self::WORD_MAX_LENGTH) {
                return false;
            }
        }

        return true;
    }

    private static function path(#[\SensitiveParameter] string $path, DisplayPathMode $mode): string
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

    private static function query(#[\SensitiveParameter] ?string $query, DisplayPathMode $mode): string
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
            if ($eq === false) {
                // Ohne `=` ist der ganze Teil ein Wert (`?<token>`): nie zeigen, auch nicht als Name.
                $shown[] = self::PLACEHOLDER;
                continue;
            }
            $name = substr($pair, 0, $eq);
            // Werte immer verborgen, auch leere: `b=` → `b=••••`.
            $shown[] = (self::isVisibleQueryName($name) ? $name : self::PLACEHOLDER) . '=' . self::PLACEHOLDER;
        }

        return '?' . implode('&', $shown);
    }
}
