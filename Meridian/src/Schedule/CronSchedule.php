<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Cron\CronExpression;

/**
 * Cron-Ausdruck plus Zeitzone. Berechnet die nächsten Läufe so,
 * wie sie in der Oberfläche als Vorschau erscheinen.
 */
final readonly class CronSchedule
{
    private CronExpression $expression;

    public function __construct(
        string $expression,
        public \DateTimeZone $timezone,
    ) {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $expression));
        if (!CronExpression::isValidExpression($normalized)) {
            throw new \InvalidArgumentException('Ungültiger Cron-Ausdruck.');
        }
        $this->expression = new CronExpression($normalized);
    }

    public function expression(): string
    {
        return $this->expression->getExpression() ?? '';
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    public function nextRuns(\DateTimeImmutable $from, int $count = 5): array
    {
        $local = $from->setTimezone($this->timezone);
        $runs = [];
        foreach ($this->expression->getMultipleRunDates($count, $local, false, false, $this->timezone->getName()) as $date) {
            $runs[] = \DateTimeImmutable::createFromInterface($date)->setTimezone($this->timezone);
        }

        return $runs;
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return $this->expression->isDue($now->setTimezone($this->timezone), $this->timezone->getName());
    }
}
