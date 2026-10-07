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
 */
final class SecretMasker
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

    /** @var list<string> */
    private array $known = [];

    public function remember(#[\SensitiveParameter] string $secret): void
    {
        if (strlen($secret) < self::MIN_KNOWN_LENGTH || in_array($secret, $this->known, true)) {
            return;
        }

        $this->known[] = $secret;
        // Längste zuerst, damit ein Teilstring nicht den Rest freilegt.
        usort($this->known, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    public function mask(string $text): string
    {
        foreach ($this->known as $secret) {
            $text = str_replace(
                [$secret, rawurlencode($secret), urlencode($secret), base64_encode($secret)],
                self::MASK,
                $text,
            );
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
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['known' => count($this->known) . ' Werte'];
    }
}
