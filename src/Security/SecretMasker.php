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
    }

    public function mask(#[\SensitiveParameter] string $text): string
    {
        foreach ($this->known->open() as $secret) {
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
