<?php

declare(strict_types=1);

namespace Meridian\Monitor;

use Meridian\Http\ValidationFailed;
use Meridian\Job\CategoryRepository;
use Meridian\Security\CategoryScope;

/**
 * Strenge Prüfung der Eingabe einer Überwachung: unbekannte Felder und falsche Typen werden abgelehnt, nichts wird
 * zurechtgeschnitten. Die Kategorie muss im Bereich des Bearbeitungsrechts liegen; „unbekannt“ und „nicht erlaubt“ sehen gleich aus.
 */
final class MonitorValidator
{
    public const MIN_INTERVAL = 60;
    public const MAX_INTERVAL = 2_678_400;
    public const MAX_GRACE = 86_400;
    public const DEFAULT_GRACE = 300;

    private const KEYS = ['name', 'category_id', 'interval_seconds', 'grace_seconds', 'is_enabled', 'notify_on_down', 'notify_on_up', 'notify_channels'];

    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    /**
     * @param array<mixed> $input
     *
     * @throws ValidationFailed
     */
    public function validate(array $input, CategoryScope $editScope, ?MonitorRecord $existing): MonitorDraft
    {
        $errors = [];
        foreach (array_keys($input) as $key) {
            if (!in_array((string) $key, self::KEYS, true)) {
                $errors[(string) $key] = 'Unbekanntes Feld. Erlaubt sind: ' . implode(', ', self::KEYS) . '.';
            }
        }

        /** @var mixed $name */
        $name = array_key_exists('name', $input) ? $input['name'] : $existing?->name;
        if (!is_string($name) || preg_match('/^[^\x00-\x1F\x7F]{1,80}$/Du', $name) !== 1 || trim($name) === '') {
            $errors['name'] = 'Name: 1 bis 80 Zeichen ohne Steuerzeichen.';
        }

        /** @var mixed $interval */
        $interval = array_key_exists('interval_seconds', $input) ? $input['interval_seconds'] : $existing?->intervalSeconds;
        if (!is_int($interval) || $interval < self::MIN_INTERVAL || $interval > self::MAX_INTERVAL) {
            $errors['interval_seconds'] = 'Erwartetes Intervall: ganze Zahl von ' . self::MIN_INTERVAL . ' (1 Minute) bis ' . self::MAX_INTERVAL . ' (31 Tage) Sekunden.';
        }
        /** @var mixed $grace */
        $grace = array_key_exists('grace_seconds', $input) ? $input['grace_seconds'] : ($existing->graceSeconds ?? self::DEFAULT_GRACE);
        if (!is_int($grace) || $grace < 0 || $grace > self::MAX_GRACE) {
            $errors['grace_seconds'] = 'Toleranz: ganze Zahl von 0 bis ' . self::MAX_GRACE . ' Sekunden.';
        }

        $flags = [];
        foreach (['is_enabled' => $existing->isEnabled ?? true, 'notify_on_down' => $existing->notifyOnDown ?? true, 'notify_on_up' => $existing->notifyOnUp ?? true] as $field => $default) {
            /** @var mixed $value */
            $value = array_key_exists($field, $input) ? $input[$field] : $default;
            if (!is_bool($value)) {
                $errors[$field] = 'Muss true oder false sein.';
            } else {
                $flags[$field] = $value;
            }
        }
        /** @var mixed $channels */
        $channels = array_key_exists('notify_channels', $input) ? $input['notify_channels'] : ($existing->notifyChannels ?? 'all');
        if ($channels !== 'all' && $channels !== 'none') {
            $errors['notify_channels'] = 'Kanäle: „all“ (alle aktiven) oder „none“ (keine).';
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

        if ($errors !== [] || !is_string($name) || !is_int($interval) || !is_int($grace) || !is_string($channels)) {
            throw new ValidationFailed($errors);
        }

        return new MonitorDraft(trim($name), $categoryId, $categoryName, $interval, $grace, $flags['is_enabled'] ?? true, $flags['notify_on_down'] ?? true, $flags['notify_on_up'] ?? true, $channels);
    }
}
