<?php

declare(strict_types=1);

namespace Meridian\User;

use Meridian\Http\ValidationFailed;

/**
 * Prüft die Zuweisungen aus einem Anfragekörper streng (ADR 0005, §3.2): ablehnen, nie zurechtschneiden oder
 * umdeuten (`"2"` ist keine Rollen-ID, `1` ist kein `true`). Meldungen sind feste Texte ohne Eingabewert.
 *
 * Geltungsbereich: `all_categories: true` mit `category_ids: []` **oder** `false` mit 1–100 eindeutigen, vorhandenen
 * IDs. „Alle“ entsteht nie aus einer leeren Liste. Eine Rolle mit gefährlichem Recht nur für alle Kategorien.
 * Ob der Handelnde die Zuweisung vergeben darf, prüft danach `GrantPolicy::mayAssign()`.
 */
final class AssignmentValidator
{
    public const MAX_ASSIGNMENTS = 3;
    public const MAX_CATEGORIES = 100;

    private const KEYS = ['all_categories', 'category_ids', 'role_id'];

    public const MESSAGE_LIST = 'Zuweisungen als Liste mit höchstens drei Einträgen angeben (je Rolle höchstens einer).';
    public const MESSAGE_SHAPE = 'Jede Zuweisung braucht genau die Felder role_id, all_categories und category_ids.';
    public const MESSAGE_ROLE = 'Rolle unbekannt. Eine Rolle aus GET /api/roles wählen.';
    public const MESSAGE_ROLE_TWICE = 'Jede Rolle höchstens einmal zuweisen.';
    public const MESSAGE_FLAG = 'all_categories muss true oder false sein.';
    public const MESSAGE_IDS = 'category_ids als Liste von Kategorie-IDs (Zahlen) angeben.';
    public const MESSAGE_SCOPE = 'Kategorien wählen oder ‚Alle Kategorien‘ ankreuzen.';
    public const MESSAGE_TOO_MANY = 'Höchstens 100 verschiedene Kategorien je Zuweisung, jede nur einmal.';
    public const MESSAGE_CATEGORY = 'Kategorie unbekannt. Nur vorhandene Kategorien wählen.';
    public const MESSAGE_DANGEROUS = 'Diese Rolle gilt nur für alle Kategorien.';

    public function __construct(private readonly RoleCatalog $roles)
    {
    }

    /**
     * @param mixed $input Rohwert `assignments` aus dem Anfragekörper
     *
     * @return list<Assignment>
     *
     * @throws ValidationFailed
     */
    public function validate(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > self::MAX_ASSIGNMENTS) {
            throw ValidationFailed::field('assignments', self::MESSAGE_LIST);
        }

        $roles = [];
        foreach ($this->roles->all() as $role) {
            $roles[$role->id] = $role;
        }

        $errors = [];
        $assignments = [];
        $seenRoles = [];
        foreach ($input as $index => $entry) {
            $path = 'assignments.' . $index;
            if (!is_array($entry) || !self::hasExactKeys($entry)) {
                $errors[$path] = self::MESSAGE_SHAPE;
                continue;
            }

            $role = is_int($entry['role_id']) ? ($roles[$entry['role_id']] ?? null) : null;
            if ($role === null) {
                $errors[$path . '.role_id'] = self::MESSAGE_ROLE;
            } elseif (isset($seenRoles[$role->id])) {
                $errors[$path . '.role_id'] = self::MESSAGE_ROLE_TWICE;
            } else {
                $seenRoles[$role->id] = true;
            }

            $all = $entry['all_categories'];
            if (!is_bool($all)) {
                $errors[$path . '.all_categories'] = self::MESSAGE_FLAG;
            }

            $ids = self::intList($entry['category_ids']);
            if ($ids === null) {
                $errors[$path . '.category_ids'] = self::MESSAGE_IDS;
            }

            if ($role === null || !is_bool($all) || $ids === null || isset($errors[$path . '.role_id'])) {
                continue;
            }

            if ($all !== ($ids === [])) {
                $errors[$path . '.category_ids'] = self::MESSAGE_SCOPE;
                continue;
            }
            if (!$all && $role->isDangerous()) {
                $errors[$path . '.all_categories'] = self::MESSAGE_DANGEROUS;
                continue;
            }
            if (count($ids) > self::MAX_CATEGORIES || count(array_unique($ids)) !== count($ids)) {
                $errors[$path . '.category_ids'] = self::MESSAGE_TOO_MANY;
                continue;
            }
            if ($ids !== [] && count($this->roles->categoryNames($ids)) !== count($ids)) {
                $errors[$path . '.category_ids'] = self::MESSAGE_CATEGORY;
                continue;
            }

            $assignments[] = new Assignment($role->id, $all, $ids);
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return $assignments;
    }

    /**
     * @param array<mixed> $entry
     */
    private static function hasExactKeys(array $entry): bool
    {
        $keys = array_keys($entry);
        sort($keys, SORT_STRING);

        return $keys === self::KEYS;
    }

    /**
     * @return list<int>|null null, wenn es keine Liste positiver Ganzzahlen ist
     */
    private static function intList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1) {
                return null;
            }
            $ids[] = $id;
        }

        return $ids;
    }
}
