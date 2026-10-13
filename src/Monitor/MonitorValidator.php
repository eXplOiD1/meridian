<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Monitor;

use Meridian\Http\ValidationFailed;
use Meridian\Job\CategoryRepository;
use Meridian\Job\HttpJobConfig;
use Meridian\Runner\Http\InvalidUrl;
use Meridian\Runner\Http\UrlPolicy;
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

    private const KEYS = ['name', 'category_id', 'interval_seconds', 'grace_seconds', 'is_enabled', 'notify_on_down', 'notify_on_up', 'notify_channels', 'channel_ids', 'kind', 'target', 'expected_status', 'fail_after', 'max_response_ms'];

    public function __construct(private readonly CategoryRepository $categories, private readonly UrlPolicy $urls = new UrlPolicy())
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
        /** @var mixed $maxResponse */
        $maxResponse = array_key_exists('max_response_ms', $input) ? $input['max_response_ms'] : ($existing->maxResponseMs ?? 5000);
        if (!is_int($maxResponse)) { throw ValidationFailed::field('max_response_ms', 'Antwortzeit: ganze Zahl von 100 bis 10000 Millisekunden.'); }
        if ($maxResponse < 100 || $maxResponse > 10000) {
            $errors['max_response_ms'] = 'Antwortzeit: ganze Zahl von 100 bis 10000 Millisekunden.';
        }
        foreach (array_keys($input) as $key) {
            if (!in_array((string) $key, self::KEYS, true)) {
                $errors[(string) $key] = 'Unbekanntes Feld. Erlaubt sind: ' . implode(', ', self::KEYS) . '.';
            }
        }

        /** @var mixed $kindInput */
        $kindInput = array_key_exists('kind', $input) ? $input['kind'] : ($existing === null ? MonitorKind::Heartbeat->value : $existing->kind->value);
        $kind = is_string($kindInput) ? MonitorKind::tryFrom($kindInput) : null;
        if ($kind === null) {
            $errors['kind'] = 'Art: „heartbeat“, „http“, „tcp“ oder „ping“.';
        } elseif ($existing !== null && $kind !== $existing->kind) {
            $errors['kind'] = 'Die Art einer Überwachung lässt sich nicht ändern. Lege eine neue an.';
        }
        $target = null;
        $displayTarget = null;
        if ($kind !== null && $kind->isActive()) {
            if (array_key_exists('target', $input)) {
                /** @var mixed $targetInput */
                $targetInput = $input['target'];
                if (!is_string($targetInput)) {
                    $errors['target'] = 'Ziel: Text erwartet.';
                } else {
                    try {
                        $parsed = MonitorTarget::parse($kind, $targetInput, $this->urls);
                        $target = MonitorTarget::stored($kind, $parsed);
                        $displayTarget = MonitorTarget::display($kind, $parsed);
                    } catch (InvalidUrl $e) {
                        $errors['target'] = $e->getMessage();
                    }
                }
            } elseif ($existing === null) {
                $errors['target'] = 'Pflichtfeld: das Ziel der Prüfung.';
            }
        } elseif (array_key_exists('target', $input)) {
            $errors['target'] = 'Ein Herzschlag hat kein Ziel.';
        }
        /** @var mixed $expectedInput */
        $expectedInput = array_key_exists('expected_status', $input) ? $input['expected_status'] : ($existing->expectedStatus ?? '200-399');
        if (!is_string($expectedInput) || HttpJobConfig::parseExpectedStatus($expectedInput) === null) {
            $errors['expected_status'] = 'Erwartete Statuscodes: z. B. 200-399 oder 200,204 (Codes 100 bis 599).';
        } elseif ($kind !== MonitorKind::Http && array_key_exists('expected_status', $input)) {
            $errors['expected_status'] = 'Statuscodes gibt es nur bei einer Web-Adresse.';
        }
        /** @var mixed $failAfter */
        $failAfter = array_key_exists('fail_after', $input) ? $input['fail_after'] : ($existing->failAfter ?? 3);
        if (!is_int($failAfter) || $failAfter < 1 || $failAfter > 10) {
            $errors['fail_after'] = 'Fehlversuche bis zum Ausfall: ganze Zahl von 1 bis 10.';
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
        if (!in_array($channels, ['all', 'selected', 'none'], true)) {
            $errors['notify_channels'] = 'Kanäle: „all“, „selected“ oder „none“.';
        }

        $channelIds = [];
        if ($channels === 'selected') {
            try {
                $channelIds = \Meridian\Notify\ChannelIds::validate(array_key_exists('channel_ids', $input) ? $input['channel_ids'] : ($existing->channelIds ?? []));
            } catch (ValidationFailed $e) {
                $errors += $e->fields();
            }
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

        if ($errors !== [] || $kind === null || !is_string($name) || !is_int($interval) || !is_int($grace) || !is_string($channels) || !is_string($expectedInput) || !is_int($failAfter)) {
            throw new ValidationFailed($errors);
        }

        return new MonitorDraft(trim($name), $categoryId, $categoryName, $interval, $kind->isActive() ? 0 : $grace, $flags['is_enabled'] ?? true, $flags['notify_on_down'] ?? true, $flags['notify_on_up'] ?? true, $channels, $kind, $target, $displayTarget, $expectedInput, $failAfter, $channelIds, $maxResponse);
    }
}
