<?php

declare(strict_types=1);

namespace Meridian\Runner\Http;

use Meridian\Database\Connection;
use Meridian\Security\SecretMasker;

/**
 * Freigaben aus `http_internal_targets`. Jede Zeile läuft durch dieselbe Prüfung wie beim Anlegen
 * (InternalTarget::create()); eine ungültige Zeile wird ignoriert (sperrt also), nie großzügig gedeutet.
 * Liest nur, schreibt nie.
 */
final class DbInternalTargetSource implements InternalTargetSource
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[\Override]
    public function targetsFor(?int $categoryId): array
    {
        // Ohne Kategorie ist `category_id = NULL` nie wahr: dann nur globale Freigaben.
        $rows = $this->db->fetchAll(
            'SELECT id, kind, value, port, category_id FROM http_internal_targets
              WHERE category_id IS NULL OR category_id = :category ORDER BY id',
            ['category' => $categoryId],
        );

        $targets = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $kind = $row['kind'] ?? null;
            $value = $row['value'] ?? null;
            $port = $row['port'] ?? null;
            $category = $row['category_id'] ?? null;
            if (!is_int($id) || !is_string($kind) || !is_string($value) || !is_int($port) || ($category !== null && !is_int($category))) {
                continue;
            }
            try {
                $target = InternalTarget::create($kind, $value, $port, $category);
            } catch (InvalidInternalTarget) {
                self::log('Meridian: Freigabe interner Ziele Nr. ' . $id . ' ist ungültig und wird ignoriert. Bitte löschen und neu anlegen.');
                continue;
            }
            // Nur die exakt gespeicherte Normalform zählt (z. B. kein FD12::/16 oder Hostname mit Großbuchstaben).
            if ($target->value !== $value) {
                self::log('Meridian: Freigabe interner Ziele Nr. ' . $id . ' ist nicht normalisiert und wird ignoriert. Bitte löschen und neu anlegen.');
                continue;
            }
            $targets[] = $target;
        }

        return $targets;
    }

    /** Nur feste Texte mit der Zeilennummer, nie der Wert; trotzdem maskiert (Regel 4). */
    private static function log(string $message): void
    {
        error_log((new SecretMasker())->mask($message));
    }
}
