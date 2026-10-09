<?php

declare(strict_types=1);

namespace Meridian\Settings;

/**
 * Ergebnis einer Änderung: der neue Eintrag, alter und neuer gültiger Wert und wie viele gespeicherte
 * Anzeige-URLs dabei verschärft wurden (nur beim Wechsel von `http.display_path` auf `hidden`).
 */
final readonly class SettingChange
{
    public function __construct(
        public SettingEntry $entry,
        public int|string $old,
        public int|string $new,
        public int $tightenedJobs,
    ) {
    }
}
