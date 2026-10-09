<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

/**
 * URL-Syntax für HTTP-Jobs (docs/decisions/0003, §3.3). Dieselbe Prüfung beim Speichern und im Runner bei jedem
 * Lauf und jeder Weiterleitung. Ziel: PHP und curl lesen die URL gleich, deshalb eine enge Allowlist statt
 * parse_url(); was nicht passt, wird abgelehnt, nie zurechtgeschnitten.
 *
 * Löst nie auf (keine DNS-Abfrage); die Adressprüfung macht TargetGuard.
 */
final class UrlPolicy
{
    public const MAX_LENGTH = 2048;

    /** Namen, die unabhängig von DNS gesperrt sind. `*.localhost` zusätzlich über die Endung. */
    private const BLOCKED_NAMES = ['localhost', 'metadata', 'metadata.google.internal'];

    private const IPV4 = '/^(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(?:\.(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}$/D';

    private const LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    /** RFC 3986 für Pfad und Query: unreserved, sub-delims, ':' '@' '/' und gültige %XX. */
    private const PATH = '~^(?:[A-Za-z0-9\-._\~!$&\'()*+,;=:@/]|%[0-9A-Fa-f]{2})*$~D';
    private const QUERY = '~^(?:[A-Za-z0-9\-._\~!$&\'()*+,;=:@/?]|%[0-9A-Fa-f]{2})*$~D';

    /**
     * @throws InvalidUrl mit einer Meldung ohne die URL
     */
    public function parse(#[\SensitiveParameter] string $url): ParsedUrl
    {
        if ($url === '') {
            throw new InvalidUrl('Die URL fehlt.');
        }
        if (strlen($url) > self::MAX_LENGTH) {
            throw new InvalidUrl('Die URL ist zu lang (höchstens 2048 Byte).');
        }
        if (preg_match('/[^\x21-\x7E]/', $url) !== 0) {
            throw new InvalidUrl('Die URL enthält Leerzeichen, Steuerzeichen oder Zeichen außerhalb von ASCII. Internationale Namen bitte als Punycode (xn--…) angeben, Sonderzeichen prozentkodieren.');
        }
        if (str_contains($url, '\\')) {
            throw new InvalidUrl('Die URL enthält einen Backslash. Bitte „/“ verwenden oder das Zeichen prozentkodieren (%5C).');
        }
        if (str_contains($url, '#')) {
            throw new InvalidUrl('Die URL enthält ein Fragment (#), das nie gesendet wird. Bitte den Teil ab # entfernen.');
        }
        if (preg_match('~^([A-Za-z][A-Za-z0-9+.\-]*)://([^/?]*)(.*)$~sD', $url, $m) !== 1) {
            throw new InvalidUrl('Die URL muss mit http:// oder https:// beginnen.');
        }
        $scheme = strtolower($m[1]);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidUrl('Nur http:// und https:// sind erlaubt.');
        }
        $authority = $m[2];
        $rest = $m[3];

        if (str_contains($authority, '@')) {
            throw new InvalidUrl('Zugangsdaten in der URL (user:passwort@) sind nicht erlaubt. Bitte den Header „Authorization“ verwenden.');
        }
        if ($authority === '') {
            throw new InvalidUrl('Der Host fehlt.');
        }

        [$host, $isIp, $portText] = $this->splitAuthority($authority);
        $port = $scheme === 'https' ? 443 : 80;
        if ($portText !== null) {
            if (preg_match('/^[1-9][0-9]{0,4}$/D', $portText) !== 1 || (int) $portText > 65535) {
                throw new InvalidUrl('Ungültiger Port. Erlaubt ist eine Zahl von 1 bis 65535 ohne führende Nullen.');
            }
            $port = (int) $portText;
        }

        $queryPos = strpos($rest, '?');
        $path = $queryPos === false ? $rest : substr($rest, 0, $queryPos);
        $query = $queryPos === false ? null : substr($rest, $queryPos + 1);
        if (preg_match(self::PATH, $path) !== 1 || ($query !== null && preg_match(self::QUERY, $query) !== 1)) {
            throw new InvalidUrl('Pfad oder Query enthalten unzulässige Zeichen. Sonderzeichen bitte prozentkodieren (%XX).');
        }

        return new ParsedUrl($scheme, $host, $port, $isIp, $path, $query);
    }

    /**
     * Gültiger DNS-Name nach §3.3: Kleinbuchstaben, Ziffern, Bindestrich; Labels 1–63, gesamt ≤ 253; kein Punkt am
     * Ende. Dazu keine Zahlform, die ein IPv4-Parser (inet_aton, WHATWG-URL, curl) als Adresse lesen könnte:
     *  - kein Label mit `0x`-Präfix und nur Hex-Ziffern dahinter (`0x7f`, `0x`, `0xa9fea9fe`),
     *  - kein Label in Oktalform (führende 0 und nur Ziffern: `0177`, `00`),
     *  - letztes Label nicht rein numerisch (sonst wären `127.1` oder `2130706433` Namen).
     * So scheitern `http://0x7f000001/`, `http://0x7f.0x0.0x0.0x1/` und `http://0177.0.0.0x1/` schon an der Syntax.
     * Erlaubt bleiben Namen wie `a1b2.example.com`, `x0.example.com`, `0xfoo.example.com` oder `1.cdn.example.com`.
     */
    public static function isValidHostname(#[\SensitiveParameter] string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if (preg_match(self::LABEL, $label) !== 1 || self::isNumberLabel($label, false)) {
                return false;
            }
        }

        return !self::isNumberLabel($labels[count($labels) - 1], true);
    }

    /**
     * Zahlform eines Labels: Hex mit `0x`-Präfix und Oktal (führende Null) immer; reine Dezimalzahl nur, wenn
     * `$decimal` (für das letzte Label).
     */
    private static function isNumberLabel(string $label, bool $decimal): bool
    {
        return preg_match('/^0x[0-9a-f]*$/D', $label) === 1
            || preg_match('/^0[0-9]+$/D', $label) === 1
            || ($decimal && preg_match('/^[0-9]+$/D', $label) === 1);
    }

    /** Namen, die nie ein Ziel sein dürfen (Loopback, Metadaten-Dienste), unabhängig von DNS. */
    public static function isBlockedHostname(#[\SensitiveParameter] string $host): bool
    {
        return in_array($host, self::BLOCKED_NAMES, true) || str_ends_with($host, '.localhost');
    }

    public static function isIpv4(string $value): bool
    {
        return preg_match(self::IPV4, $value) === 1;
    }

    /**
     * @return array{0: string, 1: bool, 2: string|null} Host, ist IP-Literal, Port-Text
     */
    private function splitAuthority(#[\SensitiveParameter] string $authority): array
    {
        if ($authority[0] === '[') {
            if (preg_match('/^\[([^\[\]]*)\](?::(.*))?$/sD', $authority, $m) !== 1) {
                throw new InvalidUrl('Ungültige IPv6-Adresse. Form: http://[2001:db8::1]:8080/');
            }
            if (str_contains($m[1], '%')) {
                throw new InvalidUrl('IPv6-Adressen mit Zonen-ID (%) sind nicht erlaubt.');
            }
            $packed = filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : inet_pton($m[1]);
            $canonical = $packed === false ? false : inet_ntop($packed);
            if ($canonical === false) {
                throw new InvalidUrl('Ungültige IPv6-Adresse. Form: http://[2001:db8::1]:8080/');
            }

            return [$canonical, true, $m[2] ?? null];
        }

        if (str_contains($authority, '[') || str_contains($authority, ']') || substr_count($authority, ':') > 1) {
            throw new InvalidUrl('Ungültiger Host. IPv6-Adressen gehören in eckige Klammern.');
        }
        $colon = strpos($authority, ':');
        $host = strtolower($colon === false ? $authority : substr($authority, 0, $colon));
        $portText = $colon === false ? null : substr($authority, $colon + 1);

        if (self::isIpv4($host)) {
            return [$host, true, $portText];
        }
        if (!self::isValidHostname($host)) {
            throw new InvalidUrl('Ungültiger Hostname. Erlaubt sind Buchstaben, Ziffern, Bindestriche und Punkte (Umlaute als Punycode xn--…); IP-Adressen nur in Punktschreibweise ohne führende Nullen.');
        }
        if (self::isBlockedHostname($host)) {
            throw new InvalidUrl('Dieser Hostname ist gesperrt (localhost oder Metadaten-Dienst).');
        }

        return [$host, false, $portText];
    }
}
