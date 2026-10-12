<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Status;

use Meridian\Http\ValidationFailed;
use Meridian\Job\CategoryRepository;
use Meridian\Job\JobRepository;
use Meridian\Monitor\MonitorRepository;
use Meridian\Security\CategoryScope;

/**
 * Strenge Prüfung einer Statusseite. Die Seite liegt in einer Kategorie im Bereich von `status_pages.edit`; jeder Eintrag muss
 * ein Job oder eine Überwachung sein, die der Bearbeiter selbst sehen darf (`jobs.view`): wer etwas nicht sehen darf, kann es
 * auch nicht veröffentlichen. Unbekannt und nicht sichtbar sehen gleich aus.
 */
final class StatusPageValidator
{
    public const MAX_ITEMS = 50;

    private const KEYS = ['title', 'category_id', 'is_enabled', 'items', 'language'];
    private const ITEM_KEYS = ['type', 'id', 'label'];
    private const LABEL = '/^[^\x00-\x1F\x7F]{1,80}$/Du';

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly JobRepository $jobs,
        private readonly MonitorRepository $monitors,
    ) {
    }

    /**
     * @param array<mixed> $input
     *
     * @throws ValidationFailed
     */
    public function validate(array $input, CategoryScope $editScope, CategoryScope $viewScope, ?StatusPageRecord $existing): StatusPageDraft
    {
        $errors = [];
        foreach (array_keys($input) as $key) {
            if (!in_array((string) $key, self::KEYS, true)) {
                $errors[(string) $key] = 'Unbekanntes Feld. Erlaubt sind: ' . implode(', ', self::KEYS) . '.';
            }
        }
        /** @var mixed $title */
        $title = array_key_exists('title', $input) ? $input['title'] : $existing?->title;
        if (!is_string($title) || preg_match(self::LABEL, $title) !== 1 || trim($title) === '') {
            $errors['title'] = 'Titel: 1 bis 80 Zeichen ohne Steuerzeichen.';
        }
        /** @var mixed $enabled */
        $enabled = array_key_exists('is_enabled', $input) ? $input['is_enabled'] : ($existing->isEnabled ?? true);
        if (!is_bool($enabled)) {
            $errors['is_enabled'] = 'Muss true oder false sein.';
        }

        /** @var mixed $language */
        $language = array_key_exists('language', $input) ? $input['language'] : ($existing->language ?? 'de');
        if ($language !== 'de' && $language !== 'en') {
            $errors['language'] = 'Sprache: de oder en.';
        }

        /** @var mixed $category */
        $category = array_key_exists('category_id', $input) ? $input['category_id'] : $existing?->categoryId;
        $categoryId = null;
        $categoryName = null;
        if ($category === null) {
            if (!$editScope->isAll()) {
                $errors['category_id'] = 'Kategorie unbekannt oder nicht erlaubt.';
            }
        } else {
            $found = is_int($category) ? $this->categories->nameOf($category) : null;
            if (!is_int($category) || $found === null || !$editScope->contains($found)) {
                $errors['category_id'] = 'Kategorie unbekannt oder nicht erlaubt.';
            } else {
                $categoryId = $category;
                $categoryName = $found;
            }
        }

        $items = $existing->items ?? [];
        if (array_key_exists('items', $input)) {
            $items = $this->items($input['items'], $viewScope, $errors);
        }

        if ($errors !== [] || !is_string($title) || !is_bool($enabled) || !is_string($language)) {
            throw new ValidationFailed($errors);
        }

        return new StatusPageDraft(trim($title), $categoryId, $categoryName, $enabled, $items, $language);
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<StatusItem>
     */
    private function items(mixed $given, CategoryScope $viewScope, array &$errors): array
    {
        if (!is_array($given) || !array_is_list($given) || count($given) > self::MAX_ITEMS) {
            $errors['items'] = 'Einträge: Liste mit höchstens ' . self::MAX_ITEMS . ' Einträgen aus type (job oder monitor), id und label.';

            return [];
        }
        $out = [];
        $seen = [];
        /** @var mixed $item */
        foreach ($given as $index => $item) {
            $field = 'items.' . $index;
            $fields = self::shape($item);
            if ($fields === null) {
                $errors[$field] = 'Eintrag: Objekt mit type, id und label.';
                continue;
            }
            /** @var mixed $type */
            $type = $fields['type'] ?? null;
            /** @var mixed $id */
            $id = $fields['id'] ?? null;
            /** @var mixed $label */
            $label = $fields['label'] ?? null;
            if (($type !== StatusItem::JOB && $type !== StatusItem::MONITOR) || !is_int($id) || $id < 1) {
                $errors[$field] = 'Eintrag: type „job“ oder „monitor“ und eine positive id.';
                continue;
            }
            if (!is_string($label) || preg_match(self::LABEL, $label) !== 1 || trim($label) === '') {
                $errors[$field . '.label'] = 'Anzeigename: 1 bis 80 Zeichen ohne Steuerzeichen.';
                continue;
            }
            if (isset($seen[$type . ':' . $id])) {
                $errors[$field] = 'Dieser Eintrag steht schon auf der Seite.';
                continue;
            }
            $name = $type === StatusItem::JOB ? $this->jobs->findVisible($id, $viewScope)?->name : $this->monitors->findVisible($id, $viewScope)?->name;
            if ($name === null) {
                $errors[$field] = 'Job oder Überwachung unbekannt oder nicht erlaubt.';
                continue;
            }
            $seen[$type . ':' . $id] = true;
            $out[] = new StatusItem($type, $id, trim($label), $name);
        }

        return $out;
    }

    /**
     * @return array<mixed>|null null = kein Objekt mit genau den erlaubten Feldern
     */
    private static function shape(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }
        if ($item !== [] && array_is_list($item)) {
            return null;
        }
        if (array_diff(array_map(strval(...), array_keys($item)), self::ITEM_KEYS) !== []) {
            return null;
        }

        return $item;
    }
}
