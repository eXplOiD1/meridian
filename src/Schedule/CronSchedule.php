<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Schedule;

use Cron\CronExpression;

/**
 * Cron-Ausdruck plus Zeitzone. Ausgewertet wird in der Zeitzone des Jobs, zurückgegeben werden
 * Zeitpunkte (gespeichert wird in UTC).
 *
 * Neben den fünf Cron-Feldern gibt es das feste Intervall `@every <n>s` (ADR 0013): alle n Sekunden, ausgerichtet auf
 * Vielfache von n seit der Unix-Zeit (10 s → :00, :10, :20 …), unabhängig von Zeitzone und Sommerzeit. Die Cron-Bibliothek kennt
 * keine Sekunden. Die Untergrenze setzt die Einstellung `jobs.min_interval_seconds` beim Speichern (nicht hier).
 *
 * Sommerzeit:
 *  - übersprungene Stunde (März): ein Termin darin läuft genau einmal, zur nächsten gültigen Uhrzeit
 *    (02:30 → 03:30 Sommerzeit);
 *  - doppelte Stunde (Oktober): Jobs mit fester Uhrzeit (weder Minute noch Stunde mit „*“) laufen nur im
 *    ersten Durchgang. Intervall-Jobs („*“ in Minute oder Stunde) folgen der echten Zeit und laufen in
 *    beiden Durchgängen, wie bei cron.
 */
final readonly class CronSchedule
{
    /** Obergrenze für übersprungene Wiederholungen der doppelten Stunde je Aufruf. */
    private const MAX_REPEATED_SKIPS = 120;

    /** Obergrenze des festen Intervalls (ein Tag; darüber ist Cron die richtige Wahl). */
    public const MAX_EVERY_SECONDS = 86400;

    private ?CronExpression $expression;

    private bool $fixedTime;

    private string $normalized;

    /** Sekunden des festen Intervalls oder null bei einem Cron-Ausdruck. */
    public ?int $everySeconds;

    public function __construct(
        string $expression,
        public \DateTimeZone $timezone,
    ) {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $expression));
        if (str_starts_with($normalized, '@every ')) {
            if (preg_match('/^@every ([1-9][0-9]{0,4})s$/D', $normalized, $match) !== 1 || (int) $match[1] > self::MAX_EVERY_SECONDS) {
                throw new \InvalidArgumentException('Ungültiger Zeitplan: „@every <n>s“ mit n von 1 bis ' . self::MAX_EVERY_SECONDS . '.');
            }
            $this->everySeconds = (int) $match[1];
            $this->expression = null;
            $this->fixedTime = false;
            $this->normalized = $normalized;

            return;
        }
        if (!CronExpression::isValidExpression($normalized)) {
            throw new \InvalidArgumentException('Ungültiger Cron-Ausdruck.');
        }
        $this->normalized = $normalized;
        $this->everySeconds = null;
        $parsed = new CronExpression($normalized);
        $this->expression = $parsed;
        $minute = $parsed->getExpression(CronExpression::MINUTE) ?? '*';
        $hour = $parsed->getExpression(CronExpression::HOUR) ?? '*';
        $this->fixedTime = !str_contains($minute, '*') && !str_contains($hour, '*');
    }

    /**
     * Zeitplan eines gespeicherten Jobs. Die Zeitzone muss ein Bezeichner aus der tz-Datenbank sein
     * (`Europe/Berlin`), keine Abkürzung und kein fester Versatz.
     *
     * @throws \InvalidArgumentException bei ungültigem Ausdruck oder unbekannter Zeitzone
     */
    public static function forJob(string $expression, string $timezone): self
    {
        if ($timezone === '' || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Unbekannte Zeitzone. Erwartet wird ein Bezeichner wie „Europe/Berlin“.');
        }

        return new self($expression, new \DateTimeZone($timezone));
    }

    public function expression(): string
    {
        return $this->normalized;
    }

    /**
     * Erster Termin strikt nach $after, in der Zeitzone des Jobs.
     *
     * @throws \RuntimeException wenn der Ausdruck keinen weiteren Termin hat (z. B. 30. Februar)
     */
    public function nextAfter(\DateTimeImmutable $after): \DateTimeImmutable
    {
        if ($this->everySeconds !== null) {
            $next = (intdiv($after->getTimestamp(), $this->everySeconds) + 1) * $this->everySeconds;

            return (new \DateTimeImmutable('@' . $next))->setTimezone($this->timezone);
        }
        $expression = $this->expression ?? throw new \LogicException('Cron-Ausdruck fehlt.');
        $current = $after;
        for ($i = 0; $i <= self::MAX_REPEATED_SKIPS; ++$i) {
            $next = \DateTimeImmutable::createFromInterface(
                $expression->getNextRunDate($current->setTimezone($this->timezone), 0, false, $this->timezone->getName()),
            )->setTimezone($this->timezone);
            if ($this->fixedTime) {
                $shifted = $this->shiftedGapAfter($after, $next);
                if ($shifted !== null) {
                    return $shifted;
                }
            }
            // Die Bibliothek arbeitet minutengenau; ein Termin muss echt nach $after liegen.
            if ($next->getTimestamp() <= $after->getTimestamp()) {
                $current = $after->modify('+1 minute');
                continue;
            }
            // Die Bibliothek liefert um die Oktober-Umstellung teils Zeitpunkte, deren Ortszeit gar nicht zum
            // Ausdruck passt (z. B. 02:00 MEZ für „0 3 * * *“): verwerfen und weitersuchen.
            if (!$this->matchesWallTime($next)) {
                $current = $next;
                continue;
            }
            if ($this->fixedTime && $this->isRepeatedWallTime($next)) {
                $current = $next;
                continue;
            }

            return $next;
        }

        throw new \RuntimeException('Zeitplan liefert keinen weiteren Termin.');
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    public function nextRuns(\DateTimeImmutable $from, int $count = 5): array
    {
        $runs = [];
        $current = $from;
        for ($i = 0; $i < $count; ++$i) {
            $current = $this->nextAfter($current);
            $runs[] = $current;
        }

        return $runs;
    }

    /** Auch halbstündige Zeitumstellungen verschieben feste Termine um die tatsächliche Lücke. */
    private function shiftedGapAfter(\DateTimeImmutable $after, \DateTimeImmutable $before): ?\DateTimeImmutable
    {
        $transitions = $this->timezone->getTransitions($after->getTimestamp() - 86400, $before->getTimestamp() + 1);
        if ($transitions === false) {
            return null;
        }
        $expression = $this->expression ?? throw new \LogicException('Cron-Ausdruck fehlt.');
        $previousOffset = null;
        foreach ($transitions as $transition) {
            $offset = $transition['offset'];
            if ($previousOffset !== null && $offset > $previousOffset) {
                $gap = $offset - $previousOffset;
                for ($seconds = 0; $seconds < $gap; $seconds += 60) {
                    $timestamp = $transition['ts'] + $seconds;
                    if ($timestamp <= $after->getTimestamp() || $timestamp >= $before->getTimestamp()) {
                        continue;
                    }
                    $wall = new \DateTimeImmutable('@' . ($timestamp + $previousOffset));
                    if ($expression->isDue($wall, 'UTC')) {
                        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone);
                    }
                }
            }
            $previousOffset = $offset;
        }

        return null;
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        if ($this->everySeconds !== null) {
            return $now->getTimestamp() % $this->everySeconds === 0;
        }

        return ($this->expression ?? throw new \LogicException('Cron-Ausdruck fehlt.'))->isDue($now->setTimezone($this->timezone), $this->timezone->getName());
    }

    /**
     * Passt die Ortszeit zum Ausdruck? Ausnahme: Termine aus der übersprungenen Stunde im März verschiebt die
     * Bibliothek um die Lücke nach hinten (02:30 → 03:30); sie zählen, wenn die ursprüngliche Ortszeit in der
     * Lücke liegt und zum Ausdruck passt.
     */
    private function matchesWallTime(\DateTimeImmutable $local): bool
    {
        $expression = $this->expression ?? throw new \LogicException('Cron-Ausdruck fehlt.');
        if ($expression->isDue(self::wallClock($local), 'UTC')) {
            return true;
        }

        $timestamp = $local->getTimestamp();
        $transitions = $this->timezone->getTransitions($timestamp - 86400, $timestamp + 1);
        if ($transitions === false) {
            return false;
        }
        $previousOffset = null;
        foreach ($transitions as $transition) {
            $offset = $transition['offset'];
            if ($previousOffset !== null && $offset > $previousOffset) {
                $original = self::wallClock($local)->modify('-' . ($offset - $previousOffset) . ' seconds');
                $inGap = (new \DateTimeImmutable($original->format('Y-m-d H:i'), $this->timezone))->format('Y-m-d H:i') !== $original->format('Y-m-d H:i');
                if ($inGap && $expression->isDue($original, 'UTC')) {
                    return true;
                }
            }
            $previousOffset = $offset;
        }

        return false;
    }

    /**
     * Dieselbe Wanduhrzeit als UTC-Zeitpunkt: so prüft die Bibliothek nur die Felder, ohne eigene
     * Sommerzeit-Umrechnung.
     */
    private static function wallClock(\DateTimeImmutable $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local->format('Y-m-d H:i:00'), new \DateTimeZone('UTC'));
    }

    /**
     * Liegt die lokale Uhrzeit im zweiten Durchgang einer zurückgestellten Stunde, gab es dieselbe
     * Wanduhrzeit kurz vorher schon einmal.
     */
    private function isRepeatedWallTime(\DateTimeImmutable $local): bool
    {
        $timestamp = $local->getTimestamp();
        $transitions = $this->timezone->getTransitions($timestamp - 86400, $timestamp + 1);
        if ($transitions === false || $transitions === []) {
            return false;
        }

        $wall = $local->format('Y-m-d H:i');
        $previousOffset = null;
        foreach ($transitions as $transition) {
            $offset = $transition['offset'];
            if ($previousOffset !== null && $offset < $previousOffset) {
                $earlier = $local->setTimestamp($timestamp - ($previousOffset - $offset));
                if ($earlier->format('Y-m-d H:i') === $wall) {
                    return true;
                }
            }
            $previousOffset = $offset;
        }

        return false;
    }
}
