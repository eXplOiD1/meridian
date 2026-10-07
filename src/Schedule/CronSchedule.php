<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Cron\CronExpression;

/**
 * Cron-Ausdruck plus Zeitzone. Ausgewertet wird in der Zeitzone des Jobs, zurückgegeben werden
 * Zeitpunkte (gespeichert wird in UTC).
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

    private CronExpression $expression;

    private bool $fixedTime;

    public function __construct(
        string $expression,
        public \DateTimeZone $timezone,
    ) {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $expression));
        if (!CronExpression::isValidExpression($normalized)) {
            throw new \InvalidArgumentException('Ungültiger Cron-Ausdruck.');
        }
        $this->expression = new CronExpression($normalized);
        $minute = $this->expression->getExpression(CronExpression::MINUTE) ?? '*';
        $hour = $this->expression->getExpression(CronExpression::HOUR) ?? '*';
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
        return $this->expression->getExpression() ?? '';
    }

    /**
     * Erster Termin strikt nach $after, in der Zeitzone des Jobs.
     *
     * @throws \RuntimeException wenn der Ausdruck keinen weiteren Termin hat (z. B. 30. Februar)
     */
    public function nextAfter(\DateTimeImmutable $after): \DateTimeImmutable
    {
        $current = $after;
        for ($i = 0; $i <= self::MAX_REPEATED_SKIPS; ++$i) {
            $next = \DateTimeImmutable::createFromInterface(
                $this->expression->getNextRunDate($current->setTimezone($this->timezone), 0, false, $this->timezone->getName()),
            )->setTimezone($this->timezone);
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

    public function isDue(\DateTimeImmutable $now): bool
    {
        return $this->expression->isDue($now->setTimezone($this->timezone), $this->timezone->getName());
    }

    /**
     * Passt die Ortszeit zum Ausdruck? Ausnahme: Termine aus der übersprungenen Stunde im März verschiebt die
     * Bibliothek um die Lücke nach hinten (02:30 → 03:30); sie zählen, wenn die ursprüngliche Ortszeit in der
     * Lücke liegt und zum Ausdruck passt.
     */
    private function matchesWallTime(\DateTimeImmutable $local): bool
    {
        if ($this->expression->isDue(self::wallClock($local), 'UTC')) {
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
                if ($inGap && $this->expression->isDue($original, 'UTC')) {
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
