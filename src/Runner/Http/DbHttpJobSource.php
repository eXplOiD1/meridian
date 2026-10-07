<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Database\Connection;

/**
 * {@see HttpJobSource} aus der Tabelle `jobs`. Die Kategorie kommt aus dem gespeicherten Datensatz (für die
 * Freigaben interner Ziele), nie aus dem Auftrag.
 */
final class DbHttpJobSource implements HttpJobSource
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[\Override]
    public function load(int $jobId): ?StoredHttpJob
    {
        $row = $this->db->fetchOne(
            'SELECT type, category_id, config_json, payload_enc FROM jobs WHERE id = :id',
            ['id' => $jobId],
        );
        if ($row === null) {
            return null;
        }
        $type = $row['type'] ?? null;
        $category = $row['category_id'] ?? null;
        $config = $row['config_json'] ?? null;
        $payload = $row['payload_enc'] ?? null;
        if (!is_string($type) || ($category !== null && !is_int($category)) || !is_string($config) || !is_string($payload)) {
            return null;
        }

        return new StoredHttpJob($type, $category, $config, $payload);
    }
}
