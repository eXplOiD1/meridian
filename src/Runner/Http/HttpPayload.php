<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Security\Sealed;
use Meridian\Security\SecretMasker;

/**
 * Der geheime Teil eines HTTP-Jobs: URL, Header (Namen und Werte), Body. Gespeichert nur als
 * `SecretBox::encrypt(toJson())` in `jobs.payload_enc` (docs/decisions/0003, E1).
 *
 * Format v1: `{"v": 1, "url": "https://…", "headers": [["Name", "Wert"]], "body": null}`. Andere Versionen
 * werden abgelehnt; ein künftiges Format wird beim Lesen umgedeutet, nie per SQL umgeschrieben.
 *
 * Die Werte liegen versiegelt ({@see Sealed}) und erscheinen in keiner Darstellung (var_dump, print_r,
 * var_export, debug_zval_dump); json_encode() liefert nur Flags, serialize() wirft.
 * Die Regeln für URL, Header und Body gelten beim Speichern und beim Lesen im Runner gleich.
 */
final class HttpPayload implements \JsonSerializable
{
    public const VERSION = 1;
    public const MAX_HEADERS = 30;
    public const MAX_HEADER_VALUE_BYTES = 4096;
    public const MAX_HEADER_TOTAL_BYTES = 16384;
    public const MAX_BODY_BYTES = 65536;

    /** Diese Header setzt der Transport selbst; ein eigener Wert könnte Pinning oder Framing umgehen. */
    private const FORBIDDEN_HEADERS = ['host', 'content-length', 'transfer-encoding', 'connection', 'upgrade', 'te', 'trailer', 'keep-alive', 'expect'];

    private const HEADER_NAME = '/^[!#$%&\'*+.^_`|~0-9A-Za-z\-]{1,64}$/D';

    /** Ab dieser Länge registriert registerIn() Blattwerte aus JSON und unklare Pfadsegmente. */
    private const MIN_FRAGMENT_LENGTH = 8;

    private readonly ParsedUrl $parsed;

    /** @var Sealed<string> */
    private readonly Sealed $url;

    /** @var Sealed<list<array{0: string, 1: string}>> */
    private readonly Sealed $headers;

    /** @var Sealed<string|null> */
    private readonly Sealed $body;

    private readonly int $headerCount;

    private readonly bool $hasBody;

    /**
     * @param list<array{0: string, 1: string}> $headers
     *
     * @throws InvalidPayload|InvalidUrl
     */
    public function __construct(
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] array $headers = [],
        #[\SensitiveParameter] ?string $body = null,
    ) {
        $this->parsed = (new UrlPolicy())->parse($url);
        self::checkHeaders($headers);
        if ($body !== null && strlen($body) > self::MAX_BODY_BYTES) {
            throw new InvalidPayload('request.body', 'Der Body ist zu groß (höchstens 64 KiB).');
        }
        if ($body !== null && preg_match('//u', $body) !== 1) {
            throw new InvalidPayload('request.body', 'Der Body muss gültiger UTF-8-Text sein.');
        }
        $this->url = new Sealed($url);
        $this->headers = new Sealed($headers);
        $this->body = new Sealed($body);
        $this->headerCount = count($headers);
        $this->hasBody = $body !== null;
    }

    /**
     * @throws InvalidPayload nur mit fester Meldung, nie mit Inhalt
     */
    public static function fromJson(#[\SensitiveParameter] string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidPayload::unreadable();
        }
        if (!is_array($data) || !array_key_exists('v', $data)) {
            throw InvalidPayload::unreadable();
        }
        if ($data['v'] !== self::VERSION) {
            throw InvalidPayload::unknownVersion();
        }
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['body', 'headers', 'url', 'v'] || !is_string($data['url']) || !is_array($data['headers'])
            || !array_is_list($data['headers']) || ($data['body'] !== null && !is_string($data['body']))) {
            throw InvalidPayload::unreadable();
        }
        $headers = [];
        foreach ($data['headers'] as $header) {
            if (!is_array($header) || count($header) !== 2 || !isset($header[0], $header[1]) || !is_string($header[0]) || !is_string($header[1])) {
                throw InvalidPayload::unreadable();
            }
            $headers[] = [$header[0], $header[1]];
        }

        try {
            return new self($data['url'], $headers, $data['body']);
        } catch (InvalidPayload | InvalidUrl) {
            throw InvalidPayload::unreadable();
        }
    }

    public function toJson(): string
    {
        return json_encode(
            ['v' => self::VERSION, 'url' => $this->url->open(), 'headers' => $this->headers->open(), 'body' => $this->body->open()],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Die URL genau wie gespeichert. Nur für den Bearbeiten-Endpunkt `GET /api/jobs/{id}/source` (Einstellung
     * `jobs.reveal_for_edit`, ADR 0003 N1); der Runner nutzt {@see self::url()}.
     */
    public function urlText(): string
    {
        return $this->url->open();
    }

    public function url(): ParsedUrl
    {
        return $this->parsed;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function headers(): array
    {
        return $this->headers->open();
    }

    public function body(): ?string
    {
        return $this->body->open();
    }

    public function headerCount(): int
    {
        return $this->headerCount;
    }

    public function hasBody(): bool
    {
        return $this->hasBody;
    }

    /**
     * Registriert jeden geheimen Bestandteil im Masker des Laufs (§5.3 Schritt 3). Sofort nach dem
     * Entschlüsseln aufrufen, vor jeder Ausgabe. Lieber zu viel als zu wenig: der Masker ignoriert selbst
     * Werte unter vier Zeichen.
     */
    public function registerIn(SecretMasker $masker): void
    {
        $url = $this->url->open();
        $masker->remember($url);
        $masker->remember($this->parsed->toUrl());
        $query = $this->parsed->query();
        // URL ohne Query, normalisiert und roh (Schema/Host-Schreibweise, Standardport können abweichen).
        $masker->remember($query === null ? $this->parsed->toUrl() : substr($this->parsed->toUrl(), 0, -strlen($query) - 1));
        $rawBase = strstr($url, '?', true);
        $masker->remember($rawBase === false ? $url : $rawBase);

        foreach (explode('/', $this->parsed->path()) as $segment) {
            if (strlen($segment) >= self::MIN_FRAGMENT_LENGTH && !UrlDisplay::isVisibleSegment($segment)) {
                self::rememberEncoded($masker, $segment);
            }
        }
        if ($query !== null) {
            $masker->remember($query);
            foreach (explode('&', $query) as $pair) {
                $eq = strpos($pair, '=');
                $name = $eq === false ? $pair : substr($pair, 0, $eq);
                // Ohne `=` ist der Teil ein Wert; unsichtbare Namen sind es ebenso. Sichtbare Namen (Wörter, in der
                // Anzeige-URL ohnehin zu sehen) ab acht Zeichen zusätzlich: kostet nur Lesbarkeit, nie Sicherheit.
                if ($eq === false || !UrlDisplay::isVisibleQueryName($name) || strlen($name) >= self::MIN_FRAGMENT_LENGTH) {
                    self::rememberEncoded($masker, $name);
                }
                if ($eq !== false) {
                    self::rememberEncoded($masker, substr($pair, $eq + 1));
                }
            }
        }

        $isForm = false;
        foreach ($this->headers->open() as [$name, $value]) {
            $masker->remember($value);
            if (preg_match('/^(bearer|basic|token)\s+(\S+)$/iD', $value, $m) === 1) {
                $masker->remember($m[2]);
                if (strtolower($m[1]) === 'basic') {
                    $decoded = base64_decode($m[2], true);
                    if ($decoded !== false) {
                        $masker->remember($decoded);
                        foreach (explode(':', $decoded, 2) as $part) {
                            $masker->remember($part);
                        }
                    }
                }
            }
            if (strtolower($name) === 'content-type' && stripos($value, 'application/x-www-form-urlencoded') !== false) {
                $isForm = true;
            }
        }

        $body = $this->body->open();
        if ($body !== null) {
            $masker->remember($body);
            try {
                self::rememberLeaves($masker, json_decode($body, true, 64, JSON_THROW_ON_ERROR));
            } catch (\JsonException) {
                // kein JSON: der Body als Ganzes ist registriert
            }
            if ($isForm) {
                foreach (explode('&', $body) as $pair) {
                    $eq = strpos($pair, '=');
                    if ($eq !== false) {
                        self::rememberEncoded($masker, substr($pair, $eq + 1));
                    }
                }
            }
        }
    }

    /**
     * @return array<string, int|bool>
     */
    public function __debugInfo(): array
    {
        return ['v' => self::VERSION, 'header_count' => $this->headerCount, 'has_body' => $this->hasBody];
    }

    /**
     * Nur Flags; den gespeicherten Inhalt liefert ausschließlich toJson().
     *
     * @return array<string, int|bool>
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
        throw new \LogicException('Eine Anfrage mit Geheimnissen wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Eine Anfrage mit Geheimnissen wird nicht deserialisiert.');
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     */
    private static function checkHeaders(#[\SensitiveParameter] array $headers): void
    {
        if (count($headers) > self::MAX_HEADERS) {
            throw new InvalidPayload('request.headers', 'Zu viele Header (höchstens 30).');
        }
        $total = 0;
        foreach ($headers as $i => [$name, $value]) {
            $field = 'request.headers[' . $i . ']';
            if (preg_match(self::HEADER_NAME, $name) !== 1) {
                throw new InvalidPayload($field . '.name', 'Ungültiger Header-Name. Erlaubt sind 1–64 Zeichen aus Buchstaben, Ziffern und !#$%&\'*+-.^_`|~.');
            }
            $lower = strtolower($name);
            if (in_array($lower, self::FORBIDDEN_HEADERS, true) || str_starts_with($lower, 'proxy-')) {
                throw new InvalidPayload($field . '.name', 'Dieser Header wird von Meridian selbst gesetzt und ist nicht erlaubt (Host, Content-Length, Transfer-Encoding, Connection, Upgrade, TE, Trailer, Keep-Alive, Expect, Proxy-*).');
            }
            if (strlen($value) > self::MAX_HEADER_VALUE_BYTES) {
                throw new InvalidPayload($field . '.value', 'Der Header-Wert ist zu lang (höchstens 4096 Byte).');
            }
            if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidPayload($field . '.value', 'Der Header-Wert enthält Steuerzeichen (z. B. Zeilenumbruch) oder ungültiges UTF-8.');
            }
            if ($value !== trim($value, " \t")) {
                throw new InvalidPayload($field . '.value', 'Der Header-Wert darf nicht mit Leerzeichen beginnen oder enden.');
            }
            $total += strlen($name) + strlen($value);
        }
        if ($total > self::MAX_HEADER_TOTAL_BYTES) {
            throw new InvalidPayload('request.headers', 'Die Header sind zusammen zu groß (höchstens 16 KiB).');
        }
    }

    /**
     * Wert roh und dekodiert, dazu jedes Teilstück ab acht Zeichen zwischen Trennzeichen (Telegram
     * `bot123456:TOKEN`, Matrix-Parameter `;key=TOKEN`): ein Ziel gibt oft nur den Token-Teil zurück.
     */
    private static function rememberEncoded(SecretMasker $masker, #[\SensitiveParameter] string $value): void
    {
        foreach ([$value, rawurldecode($value), urldecode($value)] as $form) {
            $masker->remember($form);
            $pieces = preg_split('/[:;=,@]/', $form);
            foreach ($pieces === false ? [] : $pieces as $piece) {
                if (strlen($piece) >= self::MIN_FRAGMENT_LENGTH) {
                    $masker->remember($piece);
                }
            }
        }
    }

    private static function rememberLeaves(SecretMasker $masker, #[\SensitiveParameter] mixed $value): void
    {
        $remember = static function (mixed $leaf) use ($masker): void {
            if (is_string($leaf) && strlen($leaf) >= self::MIN_FRAGMENT_LENGTH) {
                $masker->remember($leaf);
            }
        };
        if (is_array($value)) {
            array_walk_recursive($value, $remember);
        } else {
            $remember($value);
        }
    }
}
