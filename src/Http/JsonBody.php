<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Http;

/**
 * Liest einen JSON-Anfragekörper als Objekt (assoziatives Array), mit Größen- und Tiefenlimit.
 */
final class JsonBody
{
    /**
     * @param int $depth Höchste erlaubte Verschachtelungstiefe (Standard 4), auf 1 bis 8 begrenzt
     *
     * @return array<mixed>|null null bei zu großem, ungültigem, zu tiefem oder nicht-objektförmigem Inhalt
     */
    public static function object(#[\SensitiveParameter] string $body, int $maxBytes, int $depth = 4): ?array
    {
        if (strlen($body) > $maxBytes) {
            return null;
        }

        try {
            return self::onlyArray(json_decode($body, true, max(1, min(8, $depth)), JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Wie {@see self::object()}, wirft aber bei ungültigem Inhalt und bei einer Liste statt eines Objekts
     * (`[]` bleibt ein leeres Objekt) eine {@see ValidationFailed} mit der Meldung für das Feld `body`.
     *
     * @param string $message sagt dem Aufrufer, was erwartet wird (Größe, Tiefe), und enthält nie den Inhalt
     *
     * @return array<mixed>
     *
     * @throws ValidationFailed
     */
    public static function objectOrFail(#[\SensitiveParameter] string $body, int $maxBytes, string $message, int $depth = 4): array
    {
        $data = self::object($body, $maxBytes, $depth);
        if ($data === null || ($data !== [] && array_is_list($data))) {
            throw ValidationFailed::field('body', $message);
        }

        return $data;
    }

    /**
     * Prüft die Feldnamen eines Anfragekörpers: fehlende Pflichtfelder und unbekannte Felder → {@see ValidationFailed}.
     * Die Meldungen nennen nur Feldnamen, nie Werte.
     *
     * @param array<mixed> $data
     * @param list<string> $required
     * @param list<string> $optional
     *
     * @throws ValidationFailed
     */
    public static function requireFields(#[\SensitiveParameter] array $data, array $required, array $optional = []): void
    {
        $allowed = [...$required, ...$optional];
        $errors = [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                $errors[$field] = 'Pflichtfeld fehlt.';
            }
        }
        if ($errors === []) {
            foreach (array_keys($data) as $key) {
                if (!in_array((string) $key, $allowed, true)) {
                    $errors['body'] = 'Unbekannte Felder im Anfragekörper. Erlaubt: ' . ($allowed === [] ? 'keine' : implode(', ', $allowed)) . '.';
                    break;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
    }

    /**
     * @return array<mixed>|null
     */
    private static function onlyArray(#[\SensitiveParameter] mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
