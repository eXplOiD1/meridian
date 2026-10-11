<?php

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Entfernt Geheimnisse aus jedem Text, der das System verlässt:
 * Verlauf, Logs, Benachrichtigungen, API-Antworten, Fehlermeldungen.
 *
 * Zwei Stufen:
 *  1. bekannte Werte (die entschlüsselten Geheimnisse eines Jobs) werden exakt ersetzt,
 *  2. typische Muster (key=…, Authorization-Header, Zugangsdaten in URLs) werden immer ersetzt.
 *
 * Die bekannten Werte liegen versiegelt ({@see Sealed}) und erscheinen in keiner Darstellung (var_dump,
 * print_r, var_export, json_encode); der Masker ist nicht serialisierbar.
 */
final class SecretMasker implements \JsonSerializable
{
    public const MASK = '••••';

    /** Kürzere Werte werden nicht exakt ersetzt, sonst würde jedes "1" oder "ok" maskiert. */
    private const MIN_KNOWN_LENGTH = 4;

    private const PATTERNS = [
        // ?key=…, &token=…, password=… in URLs, Query-Strings und Formularen
        '/(?:(?<=[?&;\s"\'])|^)((?:api[_-]?key|key|token|access[_-]?token|secret|client[_-]?secret|password|passwd|pwd|auth|signature|sig)=)[^&#\s"\'<>]+/i' => '$1' . self::MASK,
        // Authorization: Bearer …, Authorization: Basic …
        '/(authorization\s*[:=]\s*(?:bearer|basic|token)\s+)[^\s"\'<>]+/i' => '$1' . self::MASK,
        // X-Api-Key: …, X-Auth-Token: …
        '/((?:x-api-key|x-auth-token|api-key)\s*[:=]\s*)[^\s"\'<>]+/i' => '$1' . self::MASK,
        // https://user:pass@host
        '#(\b[a-z][a-z0-9+.-]*://[^/\s:@]+:)[^@\s/]+(@)#i' => '$1' . self::MASK . '$2',
    ];

    /** @var Sealed<list<string>> */
    private Sealed $known;

    private int $count = 0;

    /** Länge der längsten erkannten Form ohne Zeilenumbruch (roh, URL-kodiert, base64), für Strom-Schnitte. */
    private int $longestForm = 0;

    public function __construct()
    {
        $this->known = new Sealed([]);
    }

    public function remember(#[\SensitiveParameter] string $secret): void
    {
        $known = $this->known->open();
        if (strlen($secret) < self::MIN_KNOWN_LENGTH || in_array($secret, $known, true)) {
            return;
        }

        $known[] = $secret;
        // Längste zuerst, damit ein Teilstring nicht den Rest freilegt.
        usort($known, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->known = new Sealed($known);
        $this->count = count($known);
        foreach (self::forms($secret) as $form) {
            if (!str_contains($form, "\n")) {
                $this->longestForm = max($this->longestForm, strlen($form));
            }
        }
    }

    /**
     * Wie viele Bytes am Ende eines unvollständigen Stroms zurückgehalten werden müssen, damit kein bekannter Wert
     * (in keiner erkannten Form ohne Zeilenumbruch) über eine Schnittkante reicht: längste Form − 1, mindestens 0.
     * Nennt nur eine Länge, nie einen Wert ({@see \Meridian\Runner\Shell\StreamMasker}).
     */
    public function holdbackBytes(): int
    {
        return max(0, $this->longestForm - 1);
    }

    /**
     * Verschiebt eine Schnittstelle in `$text` nach vorn, bis kein Vorkommen eines bekannten Werts (in einer
     * erkannten Form) sie überspannt. Liegt jedes überspannende Vorkommen vollständig in `$text` (Aufrufer hält
     * {@see self::holdbackBytes()} zurück), wird so nie ein Geheimnis geteilt. Ergebnis zwischen 0 und `$cut`.
     */
    public function safeCut(#[\SensitiveParameter] string $text, int $cut): int
    {
        $cut = max(0, min($cut, strlen($text)));
        do {
            $moved = false;
            foreach ($this->known->open() as $secret) {
                foreach (self::forms($secret) as $form) {
                    $length = strlen($form);
                    $pos = strpos($text, $form, max(0, $cut - $length + 1));
                    if ($pos !== false && $pos < $cut && $pos + $length > $cut) {
                        $cut = $pos;
                        $moved = true;
                    }
                }
            }
        } while ($moved && $cut > 0);

        return $cut;
    }

    /**
     * Beginn des frühesten Vorkommens eines **mehrzeiligen** bekannten Werts, das am Ende von `$text` erst begonnen
     * hat (der Rest ab dort ist ein echter Anfang des Werts und reicht über mindestens einen Zeilenumbruch); null =
     * keins. Ein zeilenweiser Strom hält ab dort zurück ({@see \Meridian\Runner\Shell\StreamMasker}). Nennt nur eine
     * Position, nie einen Wert.
     */
    public function openMultilineStart(#[\SensitiveParameter] string $text): ?int
    {
        $length = strlen($text);
        $earliest = null;
        foreach ($this->known->open() as $secret) {
            $break = strpos($secret, "\n");
            if ($break === false) {
                continue;
            }
            $head = substr($secret, 0, $break + 1);
            $offset = max(0, $length - strlen($secret) + 1);
            while (($pos = strpos($text, $head, $offset)) !== false && ($earliest === null || $pos < $earliest)) {
                if (substr_compare($text, $secret, $pos, $length - $pos) === 0) {
                    $earliest = $pos;

                    break;
                }
                $offset = $pos + 1;
            }
        }

        return $earliest;
    }

    public function mask(#[\SensitiveParameter] string $text): string
    {
        foreach ($this->known->open() as $secret) {
            $text = str_replace(self::forms($secret), self::MASK, $text);
        }

        foreach (self::PATTERNS as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $text);
            if ($result === null) {
                // Fehler in der Regex-Engine: lieber alles verbergen als etwas durchlassen.
                return self::MASK;
            }
            $text = $result;
        }

        return $text;
    }

    /**
     * Für einen Text, dessen Ende abgeschnitten wurde, **bevor** maskiert werden konnte (z. B. ein beim Lesen auf
     * 80 KiB begrenzter Antwort-Body): maskiert und entfernt danach ein Textende, das der Anfang eines bekannten
     * Geheimnisses (roh, URL-kodiert, base64) ist — sonst bliebe der angeschnittene Teil im Klartext stehen.
     * Schon ein Zeichen zählt: an der Schnittkante kostet ein Platzhalter mehr nichts.
     */
    public function maskCut(#[\SensitiveParameter] string $text): string
    {
        $text = $this->mask($text);
        $longest = 0;
        foreach ($this->known->open() as $secret) {
            foreach (self::forms($secret) as $form) {
                $max = min(strlen($form) - 1, strlen($text));
                for ($n = $max; $n > $longest; --$n) {
                    if (substr_compare($text, substr($form, 0, $n), -$n) === 0) {
                        $longest = $n;
                        break;
                    }
                }
            }
        }

        return $longest === 0 ? $text : substr($text, 0, -$longest) . self::MASK;
    }

    /**
     * Die erkannten Formen eines bekannten Werts.
     *
     * @return list<string>
     */
    private static function forms(#[\SensitiveParameter] string $secret): array
    {
        return [$secret, rawurlencode($secret), urlencode($secret), base64_encode($secret)];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['known' => $this->count . ' Werte'];
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Der Masker kennt Geheimnisse und wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Der Masker kennt Geheimnisse und wird nicht deserialisiert.');
    }

    /**
     * Eine Kopie kennt dieselben Werte; danach gemerkte Werte gelten nur für die jeweilige Kopie.
     */
    public function __clone()
    {
        $this->known = new Sealed($this->known->open());
    }
}
