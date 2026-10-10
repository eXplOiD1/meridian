<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Runner\Utf8;
use Meridian\Security\Sealed;
use Meridian\Security\SecretMasker;

/**
 * Maskiert einen Ausgabestrom (stdout oder stderr eines Laufs) blockweise, ohne dass ein Geheimnis über eine
 * Blockgrenze hinweg sichtbar wird (ADR 0004 E8.2/E8.3). Eine Instanz je Strom, mit dem Masker des Laufs.
 *
 *  - Weitergegeben werden nur vollständige Zeilen (bis zum letzten `\n`), maskiert mit bekannten Werten und Mustern.
 *    Hat am Ende ein mehrzeiliger bekannter Wert erst begonnen, bleiben seine Zeilen zurück
 *    ({@see SecretMasker::openMultilineStart()}).
 *  - Eine Zeile ohne Umbruch über {@see self::LONG_LINE_BYTES} wird vorab geschnitten: nie in den letzten
 *    {@see SecretMasker::holdbackBytes()} Bytes (dort kann ein Geheimnis erst beginnen), an der letzten Trennstelle
 *    (Leerraum oder `&#"'<>`) in den letzten {@see self::CUT_WINDOW} Bytes davor, nie direkt hinter einem
 *    Muster-Präfix wie `token=` oder `Bearer `, und nie mitten in einem bekannten Wert ({@see SecretMasker::safeCut()}).
 *    Ohne Trennstelle: harter Schnitt, das letzte Wort davor und das erste Wort danach werden durch `••••` ersetzt
 *    ({@see self::hidLongLine()}, Notiz {@see self::NOTE_LONG_LINE}).
 *  - NUL wird **vor** dem Maskieren entfernt (sonst setzte „GEH\0EIM“ danach ein Geheimnis zusammen), danach
 *    Nicht-UTF-8 → U+FFFD. `\r` und ANSI-Folgen bleiben Text.
 *  - Am Laufende {@see self::flush()}.
 *
 * Der zurückgehaltene Rohtext liegt versiegelt ({@see Sealed}): keine Darstellung (var_dump, print_r, var_export,
 * json_encode) zeigt ihn.
 */
final class StreamMasker
{
    public const LONG_LINE_BYTES = 8192;
    public const CUT_WINDOW = 1024;
    public const NOTE_LONG_LINE = 'Lange Zeile ohne Umbruch: Teile verborgen.';

    private const SEPARATORS = " \t\r\x0B\x0C&#\"'<>";

    /** Endet ein Teil so, gehört der Anfang des nächsten zu einem Muster-Wert: dort nicht schneiden. */
    private const PATTERN_PREFIX_AT_END = '/(?:(?:api[_-]?key|key|token|access[_-]?token|secret|client[_-]?secret|password|passwd|pwd|auth|signature|sig)=|authorization\s*[:=]\s*(?:(?:bearer|basic|token)(?:\s+[^\s"\'<>]*)?)?\s*|(?:x-api-key|x-auth-token|api-key)\s*[:=]\s*[^\s"\'<>]*|[a-z][a-z0-9+.-]*:\/\/[^\/\s@]*)$/i';

    /** So weit wird vor einer Schnittstelle nach einem Muster-Präfix gesucht (Muster-Werte ohne Trennzeichen sind lang). */
    private const PREFIX_LOOKBACK = 4096;

    /** @var Sealed<string> */
    private Sealed $pending;

    /** Nach einem harten Schnitt: das erste Wort des nächsten Teils verbergen. */
    private bool $hideLeadingWord = false;

    private bool $hidLongLine = false;

    public function __construct(private readonly SecretMasker $masker)
    {
        $this->pending = new Sealed('');
    }

    /**
     * Nimmt rohe Bytes an und gibt den Teil zurück, der jetzt sicher maskiert weitergegeben werden kann (gültiges
     * UTF-8, ohne NUL; kann leer sein).
     */
    public function feed(#[\SensitiveParameter] string $bytes): string
    {
        $pending = $this->pending->open() . str_replace("\0", '', $bytes);
        $out = '';

        $newline = strrpos($pending, "\n");
        if ($newline !== false) {
            // Ein mehrzeiliger bekannter Wert (z. B. Schlüssel in einer Umgebungsvariable), der am Ende erst begonnen
            // hat, bleibt samt seiner Zeilen zurück, bis er vollständig ist oder nicht mehr passt.
            $end = $newline + 1;
            // Geprüft wird der auszugebende Teil: auch ein Vorkommen, das erst hinter dem letzten Umbruch endet, zählt.
            $open = $this->masker->openMultilineStart(substr($pending, 0, $end));
            if ($open !== null && $open < $end) {
                $before = strrpos(substr($pending, 0, $open), "\n");
                $end = $before === false ? 0 : $before + 1;
            }
            if ($end > 0) {
                $out .= $this->emit(substr($pending, 0, $end));
                $pending = substr($pending, $end);
            }
        }

        // Lange Zeilen nur schneiden, wenn nichts Mehrzeiliges zurückgehalten wird (sonst begrenzt dessen Länge).
        while (!str_contains($pending, "\n") && strlen($pending) > self::LONG_LINE_BYTES) {
            $cut = $this->cutPosition($pending);
            if ($cut === null) {
                break;
            }
            [$cut, $hard] = $cut;
            $part = substr($pending, 0, $cut);
            $pending = substr($pending, $cut);
            $masked = $this->emit($part);
            if ($hard) {
                $masked = self::hideLastWord($masked);
                $this->hideLeadingWord = true;
                $this->hidLongLine = true;
            }
            $out .= $masked;
        }
        $this->pending = new Sealed($pending);

        return $out;
    }

    /** Rest am Laufende (vollständig, deshalb ohne Rückhalt) maskiert ausgeben. */
    public function flush(): string
    {
        $rest = $this->pending->open();
        $this->pending = new Sealed('');

        return $rest === '' ? '' : $this->emit($rest);
    }

    /** Wurde eine lange Zeile ohne Trennstelle hart geschnitten (Teile verborgen)? */
    public function hidLongLine(): bool
    {
        return $this->hidLongLine;
    }

    /**
     * @return array{int, bool}|null Schnittstelle und ob sie hart (ohne Trennstelle) ist; null = auf mehr Daten warten
     */
    private function cutPosition(#[\SensitiveParameter] string $pending): ?array
    {
        $limit = strlen($pending) - $this->masker->holdbackBytes();
        if ($limit <= 0) {
            return null;
        }
        $from = max(0, $limit - self::CUT_WINDOW);
        for ($i = $limit - 1; $i >= $from; --$i) {
            if (strspn($pending[$i], self::SEPARATORS) !== 1) {
                continue;
            }
            $cut = $i + 1;
            if (preg_match(self::PATTERN_PREFIX_AT_END, substr($pending, max(0, $cut - self::PREFIX_LOOKBACK), min(self::PREFIX_LOOKBACK, $cut))) === 1) {
                continue;
            }
            $safe = $this->masker->safeCut($pending, $cut);
            if ($safe > 0) {
                return [$safe, false];
            }
        }

        // Harter Schnitt: nie mitten in einem UTF-8-Zeichen, nie mitten in einem bekannten Wert.
        // Höchstens 3 Bytes zurück: ein gültiges Zeichen hat nie mehr Folgebytes; ungültige Bytes zerteilt der Schnitt
        // ohnehin nur zu U+FFFD (sonst stünde der Strom bei lauter 0x80 still und der Puffer wüchse).
        $cut = $limit;
        for ($back = 0; $back < 3 && $cut > 0 && (ord($pending[$cut]) & 0xC0) === 0x80; ++$back) {
            --$cut;
        }
        $cut = $this->masker->safeCut($pending, $cut);

        return $cut > 0 ? [$cut, true] : null;
    }

    private function emit(#[\SensitiveParameter] string $raw): string
    {
        $masked = $this->masker->mask($raw);
        if ($this->hideLeadingWord && $masked !== '') {
            $length = strlen($masked);
            $word = strcspn($masked, self::SEPARATORS . "\n");
            // Besteht der ganze Teil aus einem Wort, bleibt auch der Anfang des nächsten verborgen.
            $this->hideLeadingWord = $word === $length;
            if ($word > 0) {
                $masked = SecretMasker::MASK . substr($masked, $word);
            }
        }

        return Utf8::scrub($masked);
    }

    private static function hideLastWord(string $text): string
    {
        $length = strlen($text);
        $i = $length;
        while ($i > 0 && strspn($text[$i - 1], self::SEPARATORS . "\n") !== 1) {
            --$i;
        }

        return $i === $length ? $text : substr($text, 0, $i) . SecretMasker::MASK;
    }
}
