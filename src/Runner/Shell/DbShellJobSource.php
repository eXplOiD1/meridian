<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Database\Connection;

/**
 * {@see ShellJobSource} aus der Tabelle `jobs`. Die Kategorie kommt aus dem gespeicherten Datensatz (für
 * {@see ShellTargetPolicy}), nie aus dem Auftrag.
 */
final class DbShellJobSource implements ShellJobSource
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[\Override]
    public function load(int $jobId): ?StoredShellJob
    {
        $row = $this->db->fetchOne(
            'SELECT type, category_id, timezone, config_json, payload_enc FROM jobs WHERE id = :id',
            ['id' => $jobId],
        );
        if ($row === null) {
            return null;
        }
        $type = $row['type'] ?? null;
        $category = $row['category_id'] ?? null;
        $timezone = $row['timezone'] ?? null;
        $config = $row['config_json'] ?? null;
        $payload = $row['payload_enc'] ?? null;
        if (!is_string($type) || ($category !== null && !is_int($category)) || !is_string($timezone) || !is_string($config) || !is_string($payload)) {
            return null;
        }

        return new StoredShellJob($type, $category, $timezone, $config, $payload);
    }
}
