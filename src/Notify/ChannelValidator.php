<?php

declare(strict_types=1);

namespace Meridian\Notify;

/**
 * Prüft Kanal-Eingaben streng: Allowlist-Formate, ablehnen statt zurechtschneiden, keine Steuerzeichen (Header-Injektion).
 */
final class ChannelValidator
{
    public const MAX_RECIPIENTS = 10;

    private const HOST = '/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/D';

    private const EMAIL_KEYS = ['name', 'type', 'enabled', 'host', 'port', 'encryption', 'username', 'password', 'from', 'from_name', 'to'];
    private const NTFY_KEYS = ['name', 'type', 'enabled', 'server', 'topic', 'priority', 'token'];

    /**
     * @param array<mixed> $input
     * @param ChannelType|null $fixedType Typ eines bestehenden Kanals (unveränderlich)
     *
     * @throws InvalidChannel
     */
    public static function validate(#[\SensitiveParameter] array $input, ?ChannelType $fixedType): ChannelDraft
    {
        $type = $fixedType;
        if (!array_key_exists('type', $input)) {
            if ($type === null) {
                throw new InvalidChannel('type', 'Art des Kanals fehlt: „email“ oder „ntfy“.');
            }
        } else {
            $given = is_string($input['type']) ? ChannelType::tryFrom($input['type']) : null;
            if ($given === null) {
                throw new InvalidChannel('type', 'Art des Kanals: „email“ oder „ntfy“.');
            }
            if ($type !== null && $given !== $type) {
                throw new InvalidChannel('type', 'Die Art eines Kanals lässt sich nicht ändern.');
            }
            $type = $given;
        }
        $allowed = $type === ChannelType::Email ? self::EMAIL_KEYS : self::NTFY_KEYS;
        foreach (array_keys($input) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new InvalidChannel((string) $key, 'Unbekanntes Feld. Erlaubt sind: ' . implode(', ', $allowed) . '.');
            }
        }

        $name = self::text($input, 'name', 1, 60, null);
        $enabled = $input['enabled'] ?? true;
        if (!is_bool($enabled)) {
            throw new InvalidChannel('enabled', 'Muss true oder false sein.');
        }
        $secretField = $type->secretField();
        $secret = null;
        if (array_key_exists($secretField, $input)) {
            $secret = self::secret($input[$secretField], $secretField);
        }

        $config = $type === ChannelType::Email ? self::email($input) : self::ntfy($input);

        return new ChannelDraft($name, $type, $config, $secret, $enabled);
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    private static function email(array $input): array
    {
        $host = self::host($input['host'] ?? null, 'host');
        $port = $input['port'] ?? null;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new InvalidChannel('port', 'Port: ganze Zahl von 1 bis 65535.');
        }
        $encryption = $input['encryption'] ?? null;
        if (!is_string($encryption) || !in_array($encryption, ['none', 'starttls', 'ssl'], true)) {
            throw new InvalidChannel('encryption', 'Verschlüsselung: „none“, „starttls“ oder „ssl“.');
        }
        $username = array_key_exists('username', $input) && $input['username'] !== null ? self::text($input, 'username', 1, 255, null) : null;
        $from = self::address($input['from'] ?? null, 'from');
        $fromName = array_key_exists('from_name', $input) && $input['from_name'] !== null ? self::text($input, 'from_name', 1, 60, '/^[^<>"\\\\]+$/u') : null;
        $to = $input['to'] ?? null;
        if (!is_array($to) || !array_is_list($to) || $to === [] || count($to) > self::MAX_RECIPIENTS) {
            throw new InvalidChannel('to', 'Empfänger: Liste von 1 bis ' . self::MAX_RECIPIENTS . ' E-Mail-Adressen.');
        }
        $recipients = [];
        /** @var mixed $address */
        foreach ($to as $address) {
            $recipients[] = self::address($address, 'to');
        }

        return ['host' => $host, 'port' => $port, 'encryption' => $encryption, 'username' => $username, 'from' => $from, 'from_name' => $fromName, 'to' => array_values(array_unique($recipients))];
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    private static function ntfy(array $input): array
    {
        $server = $input['server'] ?? 'https://ntfy.sh';
        if (!is_string($server) || strlen($server) > 200 || preg_match('/^[\x21-\x7E]+$/D', $server) !== 1) {
            throw new InvalidChannel('server', 'Server: Adresse wie https://ntfy.sh.');
        }
        $parts = parse_url($server);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new InvalidChannel('server', 'Server: http:// oder https://, Host und optional Port, ohne Pfad, Benutzer oder Parameter.');
        }
        $host = self::host($parts['host'], 'server');
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new InvalidChannel('server', 'Server: ungültiger Port.');
        }
        $topic = $input['topic'] ?? null;
        if (!is_string($topic) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $topic) !== 1) {
            throw new InvalidChannel('topic', 'Thema: 1 bis 64 Zeichen aus Buchstaben, Ziffern, _ und -.');
        }
        $priority = $input['priority'] ?? 3;
        if (!is_int($priority) || $priority < 1 || $priority > 5) {
            throw new InvalidChannel('priority', 'Priorität: ganze Zahl von 1 bis 5.');
        }

        $isDefaultPort = ($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80);

        return [
            'server' => $parts['scheme'] . '://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ($isDefaultPort ? '' : ':' . $port),
            'topic' => $topic,
            'priority' => $priority,
        ];
    }

    /**
     * @param array<mixed> $input
     */
    private static function text(array $input, string $key, int $min, int $max, ?string $pattern): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || preg_match('/^[^\x00-\x1F\x7F]+$/Du', $value) !== 1 || mb_strlen($value) < $min || mb_strlen($value) > $max
            || ($pattern !== null && $pattern !== '' && preg_match($pattern, $value) !== 1)) {
            throw new InvalidChannel($key, 'Muss ' . $min . ' bis ' . $max . ' Zeichen lang sein, ohne Steuerzeichen' . ($pattern !== null ? ' und ohne < > " \\' : '') . '.');
        }

        return $value;
    }

    private static function secret(mixed $value, string $field): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || $value === '' || strlen($value) > 255 || preg_match('/^[\x21-\x7E ]+$/D', $value) !== 1) {
            throw new InvalidChannel($field, 'Höchstens 255 Zeichen, ohne Steuerzeichen und Umlaute; null löscht den gespeicherten Wert.');
        }

        return $value;
    }

    private static function host(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidChannel($field, 'Host fehlt.');
        }
        $host = strtolower(trim($value, '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        if (preg_match(self::HOST, $host) !== 1 || $host !== strtolower($value)) {
            throw new InvalidChannel($field, 'Host: Rechnername oder IP-Adresse, nur Buchstaben, Ziffern, Punkt und Bindestrich.');
        }

        return $host;
    }

    private static function address(mixed $value, string $field): string
    {
        if (!is_string($value) || strlen($value) > 254 || preg_match('/^[\x21-\x7E]+$/D', $value) !== 1 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidChannel($field, 'Keine gültige E-Mail-Adresse.');
        }

        return $value;
    }
}
