<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Database\Connection;
use Meridian\Runner\Http\HttpPayload;
use Meridian\Runner\Http\InvalidPayload;
use Meridian\Runner\JobType;
use Meridian\Runner\Shell\InvalidShellPayload;
use Meridian\Runner\Shell\ShellPayload;
use Meridian\Security\Sealed;
use Meridian\Security\SecretBox;
use Meridian\Security\SecretException;

/**
 * Entschlüsselt `jobs.payload_enc` für den Bearbeiten-Endpunkt `GET /api/jobs/{id}/source` (Einstellung
 * `jobs.reveal_for_edit`, ADR 0003 N1 / 0004 N1). Die einzige Stelle im Web-Prozess, die `payload_enc` liest.
 *
 * Rechte, Einstellung, Rate-Limit und Audit prüft der Aufrufer ({@see \Meridian\Http\JobSourceController}) vorher.
 * Das Ergebnis liegt versiegelt ({@see Sealed}); Fehler tragen nur feste Meldungen, nie Inhalt.
 */
final class JobSourceReader
{
    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $box,
    ) {
    }

    /**
     * HTTP: `{url, headers: [{name, value}], body}`; Shell: `{script, env: [{name, value}]}`.
     *
     * @return Sealed<array<string, mixed>>|null null = Job ohne gespeicherte Anfrage bzw. Skript
     *
     * @throws JobSourceUnreadable
     */
    public function read(int $jobId, JobType $type): ?Sealed
    {
        $row = $this->db->fetchOne('SELECT payload_enc FROM jobs WHERE id = :id AND type = :type', ['id' => $jobId, 'type' => $type->value]);
        $encrypted = $row['payload_enc'] ?? null;
        if (!is_string($encrypted)) {
            return null;
        }

        try {
            $json = $this->box->decrypt($encrypted);
        } catch (SecretException) {
            throw new JobSourceUnreadable();
        }

        try {
            return new Sealed($type === JobType::Http ? self::http($json) : self::shell($json));
        } catch (InvalidPayload | InvalidShellPayload) {
            throw new JobSourceUnreadable();
        } finally {
            sodium_memzero($json);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidPayload
     */
    private static function http(#[\SensitiveParameter] string $json): array
    {
        $payload = HttpPayload::fromJson($json);

        return [
            'url' => $payload->urlText(),
            'headers' => array_map(static fn (array $h): array => ['name' => $h[0], 'value' => $h[1]], $payload->headers()),
            'body' => $payload->body(),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidShellPayload
     */
    private static function shell(#[\SensitiveParameter] string $json): array
    {
        $payload = ShellPayload::fromJson($json);

        return [
            'script' => $payload->script(),
            'env' => array_map(static fn (array $e): array => ['name' => $e[0], 'value' => $e[1]], $payload->env()),
        ];
    }
}
