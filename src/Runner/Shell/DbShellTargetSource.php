<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

use Meridian\Database\Connection;

/**
 * Liest die Freigaben aus `shell_targets`. Nur lesen: Hintergrundprozesse schreiben keine Freigaben.
 */
final class DbShellTargetSource implements ShellTargetSource
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[\Override]
    public function grantsFor(ShellTarget $target, ?int $categoryId): array
    {
        // `category_id = NULL` ist nie wahr: ohne Kategorie bleiben nur die globalen Freigaben.
        $rows = $this->db->fetchAll(
            'SELECT id, category_id, users_json, default_user FROM shell_targets
              WHERE kind = :kind AND name = :name AND (category_id IS NULL OR category_id = :category)
              ORDER BY id',
            ['kind' => $target->kind->value, 'name' => $target->name, 'category' => $categoryId],
        );
        $grants = [];
        foreach ($rows as $row) {
            $grant = self::grant($target, $row);
            if ($grant !== null) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    /**
     * Eine unlesbare Zeile zählt nie als Freigabe.
     *
     * @param array<string, mixed> $row
     */
    public static function grant(ShellTarget $target, array $row): ?ShellTargetGrant
    {
        $id = $row['id'] ?? null;
        $category = $row['category_id'] ?? null;
        $usersJson = $row['users_json'] ?? null;
        $default = $row['default_user'] ?? null;
        if (!is_int($id) || ($category !== null && !is_int($category)) || !is_string($usersJson) || ($default !== null && !is_string($default))) {
            return null;
        }
        try {
            $decoded = json_decode($usersJson, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return null;
        }
        $users = [];
        foreach ($decoded as $user) {
            if (!is_string($user) || !ShellRules::isValidUser($user)) {
                return null;
            }
            $users[] = $user;
        }

        return new ShellTargetGrant($id, $target, $category, $users, $default);
    }
}
